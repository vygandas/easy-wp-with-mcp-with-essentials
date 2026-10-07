# Opens the local auto-login URL in the default browser, or prints it with -Print.
# Reads WP_SITEURL/WP_HOME and LOCAL_LOGIN_TOKEN from .env the way docker compose
# does (one pair of surrounding quotes stripped) and URL-encodes the token.
# Local environment only; see wp-content/mu-plugins/local-dev-login.php for the guards.
param([switch]$Print)
$ErrorActionPreference = 'Stop'
$envFile = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
function Get-EnvValue([string]$name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$name=" } | Select-Object -First 1
    if (-not $line) { return '' }
    $value = ($line -split '=', 2)[1].Trim()
    if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[-1] -eq '"') -or ($value[0] -eq "'" -and $value[-1] -eq "'"))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    return $value
}
$token = Get-EnvValue 'LOCAL_LOGIN_TOKEN'
if (-not $token) { throw "LOCAL_LOGIN_TOKEN is not set in $envFile" }
$base = Get-EnvValue 'WP_SITEURL'
if (-not $base) { $base = Get-EnvValue 'WP_HOME' }
if (-not $base) { $base = 'https://localhost:8443' }
$url = "$($base.TrimEnd('/'))/wp-login.php?local-login=$([uri]::EscapeDataString($token))"
if ($Print) { Write-Output $url } else { Start-Process $url }
