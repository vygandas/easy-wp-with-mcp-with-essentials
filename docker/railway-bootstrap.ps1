# One-shot production bootstrap for the linked Railway service (run once, from the repo root,
# after `railway link`). Sets every variable wp-config.php needs and adds the uploads volume.
# Salts are generated here and sent straight to Railway; they are never printed or stored.
#
#   powershell -File docker/railway-bootstrap.ps1 -SiteUrl https://your-service.up.railway.app
#
# Afterwards redeploy (git push if the service deploys from GitHub, otherwise `railway up`).
param(
    [Parameter(Mandatory = $true)][string]$SiteUrl,        # canonical site URL, no trailing slash
    [string]$MySqlService = 'MySQL',                     # name of the Railway MySQL service
    [int]$ApacheWorkers = 10,
    [switch]$SkipVolume
)
$ErrorActionPreference = 'Stop'

railway status | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'No linked Railway project. Run `railway link` first.' }

function New-Secret {
    $bytes = [byte[]]::new(32)
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    return ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
}

$vars = @(
    "WP_ENVIRONMENT_TYPE=production",
    "WP_HOME=$($SiteUrl.TrimEnd('/'))",
    "MYSQL_URL=`${{$MySqlService.MYSQL_URL}}",
    "APACHE_MAX_REQUEST_WORKERS=$ApacheWorkers"
)
foreach ($name in 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT') {
    $vars += "$name=$(New-Secret)"
}

Write-Host "Setting $($vars.Count) variables on the linked service (deploy is skipped until the end)..."
railway variable set --skip-deploys @vars
if ($LASTEXITCODE -ne 0) { throw 'railway variable set failed' }

if (-not $SkipVolume) {
    Write-Host 'Adding the uploads volume at /var/www/html/wp-content/uploads ...'
    railway volume add -m /var/www/html/wp-content/uploads
    if ($LASTEXITCODE -ne 0) { throw 'railway volume add failed (already exists? re-run with -SkipVolume)' }
}

Write-Host ''
Write-Host 'Done. Now redeploy: git push (GitHub-connected service) or `railway up`.'
Write-Host 'Then create the MCP user once the site is up:'
Write-Host '  railway ssh -- wp user create claude-mcp <email> --role=editor'
Write-Host '  railway ssh -- wp user application-password create claude-mcp claude-code --porcelain'
