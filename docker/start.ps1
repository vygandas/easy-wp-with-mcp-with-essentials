# Start the local plugin development stack. Safe to rerun: existing WordPress
# content, settings and theme are preserved. Requires Docker Desktop (Linux containers).
param([switch]$Build)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$envFile = Join-Path $repoRoot '.env'
$composeArgs = @('compose', '--project-directory', $repoRoot, '--env-file', $envFile,
    '-f', (Join-Path $repoRoot 'docker-compose.yml'))

function New-LocalSecret {
    $bytes = New-Object byte[] 32
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($bytes) } finally { $random.Dispose() }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}

function Invoke-Compose([string[]]$Arguments) {
    & docker @composeArgs @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed (exit $LASTEXITCODE). Resolve the error above and rerun docker/start.ps1."
    }
}

function Get-Setting($Settings, [string]$Name) {
    $property = $Settings.PSObject.Properties[$Name]
    if ($null -eq $property) { return '' }
    return [string]$property.Value
}

function Assert-LocalUrl([string]$Value, [string]$Name, [int]$Port) {
    $parsed = $null
    if (-not [uri]::TryCreate($Value, [UriKind]::Absolute, [ref]$parsed) -or
        $parsed.Scheme -ne 'https' -or $parsed.Host -notin @('localhost', '127.0.0.1', 'easy-wp.test') -or
        $parsed.Port -ne $Port -or $parsed.UserInfo -or $parsed.Query -or $parsed.Fragment) {
        throw "$Name must use this local Caddy stack (https://localhost:$Port). No database changes were made."
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Install and start Docker Desktop with Linux containers, then rerun this script.'
}

$saltNames = @('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT')
if (-not (Test-Path -LiteralPath $envFile)) {
    $envText = [IO.File]::ReadAllText((Join-Path $repoRoot '.env.example'))
    foreach ($name in ($saltNames + 'LOCAL_LOGIN_TOKEN')) {
        $pattern = '(?m)^' + [regex]::Escape($name) + '=\s*$'
        if (-not [regex]::IsMatch($envText, $pattern)) {
            throw ".env.example must contain an empty $name setting. No .env was written."
        }
        $envText = [regex]::Replace($envText, $pattern, "$name=$(New-LocalSecret)")
    }
    [IO.File]::WriteAllText($envFile, $envText, (New-Object Text.UTF8Encoding($false)))
    Write-Host 'Created .env with unique WordPress salts and a local login token.'
}

# Inspect Compose's resolved values rather than parsing .env ourselves: shell
# interpolation and service environment overrides must not bypass these guards.
$configuration = (Invoke-Compose @('config', '--format', 'json')) -join "`n" | ConvertFrom-Json
$wp = $configuration.services.wordpress.environment
$db = $configuration.services.db.environment
$caddy = $configuration.services.caddy.environment
if ((Get-Setting $wp 'WP_ENVIRONMENT_TYPE') -ne 'local' -or
    (Get-Setting $wp 'WP_LOCAL_STACK') -ne '1' -or
    (Get-Setting $wp 'MYSQL_URL') -or (Get-Setting $wp 'DATABASE_URL') -or
    (Get-Setting $wp 'DB_HOST') -ne 'db' -or (Get-Setting $wp 'DB_PORT') -ne '3306') {
    throw 'Refusing setup: WordPress must use WP_ENVIRONMENT_TYPE=local and the Compose db:3306 service, without MYSQL_URL or DATABASE_URL.'
}
foreach ($pair in @(@('DB_NAME', 'MYSQL_DATABASE'), @('DB_USER', 'MYSQL_USER'), @('DB_PASSWORD', 'MYSQL_PASSWORD'))) {
    if (-not (Get-Setting $wp $pair[0]) -or (Get-Setting $wp $pair[0]) -cne (Get-Setting $db $pair[1])) {
        throw "Refusing setup: resolved $($pair[0]) does not match the Compose database service."
    }
}
foreach ($service in $configuration.services.PSObject.Properties) {
    foreach ($port in $service.Value.ports) {
        if ($port.host_ip -ne '127.0.0.1') {
            throw "Refusing setup: $($service.Name) exposes a non-loopback port. Keep local auto-login private."
        }
    }
}
$siteUrl = Get-Setting $wp 'WP_HOME'
Assert-LocalUrl $siteUrl 'WP_HOME' ([int](Get-Setting $caddy 'HTTPS_PORT'))
if (([uri]$siteUrl).AbsolutePath -ne '/') { throw 'WP_HOME must point to the local site root.' }
$siteOverride = Get-Setting $wp 'WP_SITEURL'
if ($siteOverride -and $siteOverride.TrimEnd('/') -ne $siteUrl.TrimEnd('/')) {
    throw 'WP_SITEURL must match WP_HOME for this local root installation.'
}
foreach ($name in $saltNames) {
    if (-not (Get-Setting $wp $name)) { throw "Set $name in .env before starting WordPress. Existing .env was preserved." }
}
if ((Get-Setting $wp 'LOCAL_LOGIN_TOKEN') -notmatch '^[A-Za-z0-9_-]{16,}$') {
    throw 'Set LOCAL_LOGIN_TOKEN in .env to at least 16 URL-safe characters. Existing .env was preserved.'
}
if (Get-Setting $wp 'S3_UPLOADS_BUCKET') {
    if ((Get-Setting $wp 'S3_UPLOADS_ENDPOINT').TrimEnd('/') -ne 'http://minio:9000') {
        throw 'Refusing setup: local uploads must use the Compose MinIO endpoint http://minio:9000.'
    }
    Assert-LocalUrl (Get-Setting $wp 'S3_UPLOADS_BUCKET_URL') 'S3_UPLOADS_BUCKET_URL' ([int](Get-Setting $caddy 'MEDIA_HTTPS_PORT'))
}

$upArgs = @('up', '-d')
if ($Build) { $upArgs += '--build' }
Invoke-Compose $upArgs | Out-Host

# The bind mount hides the plugins the image fetched, so put the ones listed in
# docker/external-plugins.txt into the working tree too. Present ones are kept;
# delete a plugin's folder and rerun to update it.
Invoke-Compose @('exec', '-T', '-e', 'SKIP_EXISTING=1', 'wordpress', 'sh', 'docker/fetch-plugins.sh',
    'docker/external-plugins.txt', 'wp-content/plugins') | Out-Host
$externalPlugins = @(Get-Content -LiteralPath (Join-Path $PSScriptRoot 'external-plugins.txt') |
    Where-Object { $_ -match '^\s*[^#\s]' } | ForEach-Object { ($_.Trim() -split '\s+')[0] })

# A failed connection must never be mistaken for an empty installation.
Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'db', 'query', 'SELECT 1', '--skip-column-names') | Out-Null
$previousErrorPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $installedOutput = & docker @composeArgs exec -T wordpress wp core is-installed --skip-plugins --skip-themes 2>&1
    $installedExit = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorPreference
}
if ($installedExit -ne 0 -and ($installedExit -ne 1 -or ($installedOutput -join '').Trim())) {
    throw "Could not determine WordPress installation status; nothing was installed. $($installedOutput -join [Environment]::NewLine)"
}
$freshInstall = $installedExit -eq 1
if ($freshInstall) {
    # The random password is never printed or stored. docker/login.ps1 handles
    # local browser access. .test cannot receive a real installation email.
    $adminLogin = 'admin'
    $adminEmail = 'admin@localhost.test'
    $requestedLogin = Get-Setting $wp 'LOCAL_LOGIN_USER'
    if ($requestedLogin) {
        if ($requestedLogin.Contains('@')) { $adminEmail = $requestedLogin } else { $adminLogin = $requestedLogin }
    }
    $adminPassword = New-LocalSecret
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'core', 'install', "--url=$siteUrl",
        '--title=WordPress Local', "--admin_user=$adminLogin", "--admin_email=$adminEmail",
        "--admin_password=$adminPassword", '--skip-email') | Out-Host
    $adminPassword = $null
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'rewrite', 'structure', '/%postname%/') | Out-Host
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'option', 'update', 'blog_public', '0') | Out-Host
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'theme', 'activate', 'site') | Out-Host
    Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate',
        's3-uploads', 'wordpress-seo', 'safe-svg', 'webp-uploads') | Out-Host
} else {
    Write-Host 'Existing WordPress installation found; preserving its content, settings and theme.'
}

# Some plugins record the activating user (a publishing plugin uses it as the
# default author). WP-CLI normally has no logged-in user, so pick a real
# administrator for the activation hooks.
if ($externalPlugins.Count) {
    $adminIds = @(Invoke-Compose @('exec', '-T', 'wordpress', 'wp', 'user', 'list',
        '--role=administrator', '--orderby=ID', '--order=ASC', '--field=ID'))
    if (-not $adminIds.Count -or $adminIds[0] -notmatch '^\d+$') {
        throw 'No local administrator was found. Create one before activating the external plugins.'
    }
    Invoke-Compose (@('exec', '-T', 'wordpress', 'wp', 'plugin', 'activate') + $externalPlugins +
        "--user=$($adminIds[0])") | Out-Host
}

Write-Host "Local WordPress is ready at $siteUrl"
Write-Host 'If HTTPS is not trusted yet, run: powershell -File docker/trust-local-ca.ps1 (from the repo root).'
Write-Host 'Open wp-admin with: powershell -File docker/login.ps1'
Write-Host 'Edit plugin files in wp-content/plugins; the bind mount makes changes live.'
