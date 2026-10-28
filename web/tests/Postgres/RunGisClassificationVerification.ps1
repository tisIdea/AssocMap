param([string]$PostgresBin = 'C:\Program Files\PostgreSQL\17\bin', [switch]$IncludeMedia)
$ErrorActionPreference = 'Stop'
$taskRoot = Join-Path ([IO.Path]::GetTempPath()) ('assocmap-gisclass-' + [guid]::NewGuid().ToString('N'))
$cluster = Join-Path $taskRoot 'data'
$passwordFile = Join-Path $taskRoot 'password'
$server = $null
$names = @('ASSOCMAP_GISCLASS_DIRECTORY','ASSOCMAP_GISCLASS_PASSWORD','ASSOCMAP_GISCLASS_PORT')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
New-Item -ItemType Directory -Path $taskRoot | Out-Null
try {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    $rng.Dispose()
    $env:ASSOCMAP_GISCLASS_PASSWORD = [Convert]::ToBase64String($bytes)
    $env:ASSOCMAP_GISCLASS_DIRECTORY = $cluster
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,0)
    $listener.Start()
    $env:ASSOCMAP_GISCLASS_PORT = $listener.LocalEndpoint.Port.ToString()
    $listener.Stop()
    [IO.File]::WriteAllText($passwordFile,$env:ASSOCMAP_GISCLASS_PASSWORD)
    & (Join-Path $PostgresBin 'initdb.exe') -D $cluster -U assocmap_gisclass_verify -A scram-sha-256 --pwfile $passwordFile --no-locale --encoding UTF8 > (Join-Path $taskRoot 'init.log') 2>&1
    if ($LASTEXITCODE -ne 0) { throw ('Disposable initialization failed: '+$taskRoot) }
    $server = Start-Process -FilePath (Join-Path $PostgresBin 'postgres.exe') -ArgumentList @('-D',('"'+$cluster+'"'),'-h','127.0.0.1','-p',$env:ASSOCMAP_GISCLASS_PORT) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRoot 'stdout.log') -RedirectStandardError (Join-Path $taskRoot 'stderr.log')
    $ready = $false
    for ($attempt=0; $attempt -lt 40; $attempt++) {
        & (Join-Path $PostgresBin 'pg_isready.exe') -h 127.0.0.1 -p $env:ASSOCMAP_GISCLASS_PORT -t 1 > $null 2>&1
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }
        if ($server.HasExited) { break }
        Start-Sleep -Milliseconds 250
    }
    if (!$ready) { throw 'Task-owned PostgreSQL failed to start.' }
    $script = if ($IncludeMedia) { 'GisMediaClassificationVerification.php' } else { 'GisClassificationVerification.php' }
    & php (Join-Path $PSScriptRoot $script)
    if ($LASTEXITCODE -ne 0) { throw 'Native classification verification failed.' }
} finally {
    if ($server -and !$server.HasExited) {
        & (Join-Path $PostgresBin 'pg_ctl.exe') -D $cluster -m immediate -w stop > $null 2>&1
        if ($LASTEXITCODE -ne 0) { throw ('Cannot stop task-owned PostgreSQL: '+$taskRoot) }
    }
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
    $resolved = [IO.Path]::GetFullPath($taskRoot)
    $temp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')+'\'
    if (!$resolved.StartsWith($temp,[StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^assocmap-gisclass-[a-f0-9]{32}$') {
        throw 'Refusing cleanup outside task-owned temporary path.'
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
    Write-Output 'Task-owned PostgreSQL cluster/password removed.'
}
