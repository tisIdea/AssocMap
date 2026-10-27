param([string]$Filter = 'PhotoImageProcessorTest|PhotoLifecycleTest|PrivateMediaStorageTest|TestEnvironmentIsolationTest|SupabaseStorageServiceTest|MemberApplicationPhotoServiceCharacterizationTest|MemberApplicationPhotoControllerTest|MemberApplicationEvidenceTest|ProjectClosureTest|AssociationProjectsTest|GisScopedReadsTest|GisFinalizedProjectMaintenanceTest|MonitoringVisitBindingTest|MonitoringMaterialsTest|MonitoringTransactionalAuthorizationTest|AuditLogAccessTest', [switch]$WithExif)
$ErrorActionPreference = 'Stop'
$evidence = Join-Path $env:TEMP ('assocmap-media2-'+[Guid]::NewGuid().ToString('N'))
foreach ($directory in @('cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'storage/app/private', 'storage/app/public', 'phpunit-cache')) {
    New-Item -ItemType Directory -Path (Join-Path $evidence $directory) -Force | Out-Null
}
$laravelRoot = $evidence.Replace('\', '/').Substring(2)
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
    VIEW_COMPILED_PATH=($laravelRoot+'/storage/framework/views')
}
$original = @{}
$clearedNames = @(Get-ChildItem Env: | Where-Object { $_.Name -match '^(SUPABASE_|DB_|PG|REDIS_|AWS_|MAIL_|REPORTING_|MEDIA_PRIVATE_)' } | ForEach-Object { $_.Name })
foreach ($name in @($clearedNames)+@($settings.Keys) | Select-Object -Unique) {
    $original[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
Push-Location (Join-Path $PSScriptRoot '../../web')
try {
    foreach ($name in $clearedNames) { [Environment]::SetEnvironmentVariable($name, $null, 'Process') }
    foreach ($setting in $settings.GetEnumerator()) { [Environment]::SetEnvironmentVariable($setting.Key, $setting.Value, 'Process') }
    $phpOptions = @('-d', 'memory_limit=512M')
    if ($WithExif) { $phpOptions += @('-d', 'extension=exif') }
    & php @phpOptions vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --cache-directory (Join-Path $evidence 'phpunit-cache') --colors=never --filter $Filter 2>&1 | Tee-Object -FilePath (Join-Path $evidence 'tests.txt')
    $result = $LASTEXITCODE
} finally {
    Pop-Location
    foreach ($entry in $original.GetEnumerator()) { [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process') }
    Write-Output ('Evidence: '+$evidence)
}
exit $result
