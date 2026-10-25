[CmdletBinding()]
param(
    [string]$Baseline = (Join-Path $PSScriptRoot 'baselines/routes.compatibility.json'),
    [string]$Php = 'C:\php8427\php.exe',
    [string[]]$Tests = @(),
    [string[]]$RouteFiles = @('routes/web.php'),
    [string]$FormattingBaseline
)

$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ($repo.TrimEnd('\') -ne 'C:\dev\AssocMap-Refactor') { throw 'Run only in the approved refactoring worktree.' }
$web = Join-Path $repo 'web'
$Baseline = (Resolve-Path -LiteralPath $Baseline).Path
$approvedHash = 'F782E373299CF19D13D138DCAB8293171C7F04E4471A02F826DF0883A6BBD06D'
if ((Get-FileHash -LiteralPath $Baseline -Algorithm SHA256).Hash -ne $approvedHash) { throw 'Unrecognized route baseline.' }
if ($FormattingBaseline) { $FormattingBaseline = (Resolve-Path -LiteralPath $FormattingBaseline).Path }
foreach ($test in $Tests) {
    if ($test -notmatch '^tests/(Feature|Unit)/[A-Za-z0-9_]+Test\.php$' -or -not (Test-Path -LiteralPath (Join-Path $web $test))) {
        throw ('Expected an existing isolated Unit or Feature test: '+$test)
    }
}
foreach ($file in $RouteFiles) {
    if ($file -notmatch '^routes/(?:[a-z-]+/)*[a-z-]+\.php$' -or -not (Test-Path -LiteralPath (Join-Path $web $file))) {
        throw ('Expected an existing route file: '+$file)
    }
}

$evidence = Join-Path ([IO.Path]::GetTempPath()) ('assocmap-refactor-routes-'+[Guid]::NewGuid().ToString('N'))
if ([IO.Path]::GetPathRoot($evidence) -ne [IO.Path]::GetPathRoot($repo)) { throw 'Temporary output must be on the worktree drive.' }
New-Item -ItemType Directory -Path $evidence | Out-Null
foreach ($directory in @('cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'storage/app/private', 'storage/app/public', 'phpunit-cache')) {
    New-Item -ItemType Directory -Path (Join-Path $evidence $directory) -Force | Out-Null
}
# Laravel 12 recognizes a leading slash as absolute; a drive-letter cache path is rebased.
$laravelRoot = $evidence.Replace('\', '/').Substring(2)
if (-not $laravelRoot.StartsWith('/')) { throw 'Unsupported temporary path.' }
$settings = @{
    APP_ENV='testing'; APP_DEBUG='false'; APP_URL='http://localhost'
    APP_KEY='base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE='
    APP_MAINTENANCE_DRIVER='file'; BCRYPT_ROUNDS='4'; BROADCAST_CONNECTION='null'
    DB_CONNECTION='sqlite'; DB_DATABASE=':memory:'; DB_URL=''
    CACHE_STORE='array'; SESSION_DRIVER='array'; QUEUE_CONNECTION='sync'; MAIL_MAILER='array'
    LOG_CHANNEL='null'; FILESYSTEM_DISK='local'
    PULSE_ENABLED='false'; TELESCOPE_ENABLED='false'; NIGHTWATCH_ENABLED='false'
    APP_CONFIG_CACHE=($laravelRoot+'/cache/config.php'); APP_ROUTES_CACHE=($laravelRoot+'/cache/routes-v7.php')
    APP_EVENTS_CACHE=($laravelRoot+'/cache/events.php'); APP_PACKAGES_CACHE=($laravelRoot+'/cache/packages.php')
    APP_SERVICES_CACHE=($laravelRoot+'/cache/services.php'); LARAVEL_STORAGE_PATH=($laravelRoot+'/storage')
    VIEW_COMPILED_PATH=($laravelRoot+'/storage/framework/views'); ASSOCMAP_BASELINE_NATIVE=$evidence
}
$original = @{}
$clearedNames = @(Get-ChildItem Env: | Where-Object { $_.Name -match '^(SUPABASE_|DB_|PG|REDIS_|AWS_|MAIL_|REPORTING_)' } | ForEach-Object { $_.Name })
foreach ($name in @($clearedNames)+@($settings.Keys) | Select-Object -Unique) {
    $original[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

function Invoke-CheckedPhp([string]$LogName, [string[]]$Arguments) {
    & $Php @Arguments 2>&1 | Tee-Object -FilePath (Join-Path $evidence $LogName)
    if ($LASTEXITCODE -ne 0) { throw ('PHP verification failed: '+$LogName) }
}
function Invoke-IsolatedTest([string]$TestFile) {
    $name = [IO.Path]::GetFileNameWithoutExtension($TestFile)
    Invoke-CheckedPhp ($name+'.txt') @('vendor/phpunit/phpunit/phpunit', '--configuration', 'phpunit.xml',
        '--do-not-cache-result', '--cache-directory', (Join-Path $evidence 'phpunit-cache'), '--colors=never',
        '--no-progress', '--log-junit', (Join-Path $evidence ($name+'.xml')), $TestFile)
}

Push-Location $web
try {
    if ((git branch --show-current) -ne 'refactor/architecture-cleanup') { throw 'Unexpected branch.' }
    foreach ($name in $clearedNames) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    foreach ($setting in $settings.GetEnumerator()) { [Environment]::SetEnvironmentVariable($setting.Key, $setting.Value, 'Process') }
    # The existing guard installs before Laravel bootstrap and validates every connection.
    Invoke-IsolatedTest 'tests/Unit/TestEnvironmentIsolationTest.php'
    Invoke-CheckedPhp 'routes.txt' @((Join-Path $PSScriptRoot 'capture-routes.php'), $Baseline)
    foreach ($test in $Tests | Select-Object -Unique) {
        if ($test -ne 'tests/Unit/TestEnvironmentIsolationTest.php') { Invoke-IsolatedTest $test }
    }
    $phpFiles = @($RouteFiles)+@((Join-Path $PSScriptRoot 'capture-routes.php'))
    foreach ($file in $phpFiles) { Invoke-CheckedPhp (([IO.Path]::GetFileName($file))+'.syntax.txt') @('-l', $file) }
    & $Php vendor/bin/pint --test --cache-file (Join-Path $evidence 'pint.cache') --no-ansi --format=json @phpFiles 2>&1 |
        Tee-Object -FilePath (Join-Path $evidence 'pint.json')
    if ($LASTEXITCODE -gt 1) { throw 'Pint tool failed.' }
    $pint = Get-Content -LiteralPath (Join-Path $evidence 'pint.json') -Raw | ConvertFrom-Json
    if ($pint.result -notin @('passed', 'fail')) { throw 'Unrecognized Pint response.' }
    if ($pint.files.Count -gt 0 -and $FormattingBaseline) {
        $old = Get-Content -LiteralPath $FormattingBaseline -Raw | ConvertFrom-Json
        foreach ($finding in $pint.files) {
            $previous = @($old.files | Where-Object { $_.path -eq $finding.path })
            if ($previous.Count -ne 1 -or ($finding.fixers | Where-Object { $_ -notin $previous[0].fixers })) {
                throw ('New Pint findings: '+$finding.path)
            }
        }
    } elseif ($pint.files.Count -gt 0) {
        throw 'Pint findings require a reviewed pre-change -FormattingBaseline.'
    }
    git --no-optional-locks diff --check
    if ($LASTEXITCODE -ne 0) { throw 'Git whitespace check failed.' }
    $empty = Join-Path $evidence 'empty.txt'
    [IO.File]::WriteAllText($empty, '')
    foreach ($file in $phpFiles+@($PSCommandPath, (Join-Path $PSScriptRoot 'README.md'))) {
        $whitespace = @(git --no-optional-locks diff --no-index --check -- $empty $file 2>&1)
        if ($LASTEXITCODE -gt 1 -or $whitespace.Count -gt 0) { throw ('Whitespace check failed: '+$file+' '+($whitespace -join ' ')) }
    }
    Write-Output ('PASS: isolated tests, complete registry, syntax, whitespace, and no new Pint fixer findings. Evidence: '+$evidence)
} finally {
    Pop-Location
    foreach ($entry in $original.GetEnumerator()) { [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process') }
    Write-Output ('Evidence: '+$evidence)
}
