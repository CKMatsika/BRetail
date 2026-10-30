$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$projectPhp = Join-Path (Get-Location) '.runtime/php/php.exe'
$projectPg = 'C:\Program Files\PostgreSQL\16\bin\pg_ctl.exe'
if (-not (Test-Path $projectPhp)) { throw 'Install PHP 8.4.1+ or restore the project-local runtime.' }
if (Test-Path '.runtime/pgdata/PG_VERSION') {
    & $projectPg -D '.runtime/pgdata' status | Out-Null
    if ($LASTEXITCODE -ne 0) { & $projectPg -D '.runtime/pgdata' -l '.runtime/postgres.log' -o '-h 127.0.0.1 -p 55432' start }
}
& $projectPhp -S 127.0.0.1:8000 -t public

