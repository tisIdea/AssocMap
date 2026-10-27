param(
    [string] $PostgresBin = 'C:\Program Files\PostgreSQL\17\bin',
    [string] $Php = 'C:\php8427\php.exe',
    [switch] $ExtendedOnly
)

$ErrorActionPreference = 'Stop'
$taskRoot = Join-Path ([IO.Path]::GetTempPath()) ('assocmap-sec2-' + [guid]::NewGuid().ToString('N'))
$cluster = Join-Path $taskRoot 'data'
$passwordFile = Join-Path $taskRoot 'password'
$postgres = $null
$previousDirectory = $env:ASSOCMAP_SEC2_DIRECTORY
$previousPassword = $env:ASSOCMAP_SEC2_PASSWORD
New-Item -ItemType Directory -Path $taskRoot | Out-Null
try {
    $passwordBytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($passwordBytes)
    $rng.Dispose()
    $env:ASSOCMAP_SEC2_PASSWORD = [Convert]::ToBase64String($passwordBytes)
    $env:ASSOCMAP_SEC2_DIRECTORY = $cluster
    [IO.File]::WriteAllText($passwordFile, $env:ASSOCMAP_SEC2_PASSWORD)
    & (Join-Path $PostgresBin 'initdb.exe') -D $cluster -U assocmap_sec2_verify -A scram-sha-256 --pwfile $passwordFile --no-locale --encoding UTF8 > (Join-Path $taskRoot 'init.log') 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Disposable PostgreSQL initialization failed.' }
    $postgres = Start-Process -FilePath (Join-Path $PostgresBin 'postgres.exe') -ArgumentList @('-D', ('"' + $cluster + '"'), '-h', '127.0.0.1', '-p', '55444') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRoot 'stdout.log') -RedirectStandardError (Join-Path $taskRoot 'stderr.log')
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        & (Join-Path $PostgresBin 'pg_isready.exe') -h 127.0.0.1 -p 55444 -t 1 > $null 2>&1
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        if ($postgres.HasExited) { break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) { throw 'Task-owned loopback PostgreSQL did not become ready.' }
    $phpArguments = @((Join-Path $PSScriptRoot 'MemberActorVerification.php'))
    if ($ExtendedOnly) { $phpArguments += 'extended' }
    & $Php @phpArguments
    if ($LASTEXITCODE -ne 0) { throw 'Native SEC-2 verification failed.' }
} finally {
    if (Test-Path -LiteralPath (Join-Path $cluster 'postmaster.pid')) {
        & (Join-Path $PostgresBin 'pg_ctl.exe') -D $cluster -m fast -w stop > $null 2>&1
        if ($LASTEXITCODE -ne 0) { throw ('Task-owned cluster could not be stopped: ' + $taskRoot) }
    }
    $env:ASSOCMAP_SEC2_DIRECTORY = $previousDirectory
    $env:ASSOCMAP_SEC2_PASSWORD = $previousPassword
    $resolvedRoot = [IO.Path]::GetFullPath($taskRoot)
    $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
    if (-not $resolvedRoot.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase) -or
        (Split-Path $resolvedRoot -Leaf) -notmatch '^assocmap-sec2-[a-f0-9]{32}$') {
        throw 'Refusing cleanup outside the task-owned temporary directory.'
    }
    Remove-Item -LiteralPath $resolvedRoot -Recurse -Force
    Write-Output 'Task-owned PostgreSQL cluster and password removed.'
}
