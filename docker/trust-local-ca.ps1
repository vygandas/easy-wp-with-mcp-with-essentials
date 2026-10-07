# Exports Caddy's local root CA from the running container and adds it to the
# current user's Trusted Root store so https://localhost:8443 loads without a warning.
# Run after docker/start.ps1. Windows shows a confirmation
# dialog for the certificate import; accept it.
$ErrorActionPreference = 'Stop'
$out = Join-Path $PSScriptRoot 'caddy-root.crt'
Push-Location (Split-Path $PSScriptRoot -Parent)
try {
    docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt $out
    if ($LASTEXITCODE -ne 0) { throw 'Could not export the local CA. Start the Docker stack first.' }
    certutil -addstore -user Root $out
    if ($LASTEXITCODE -ne 0) { throw 'The local CA was not trusted.' }
} finally {
    Pop-Location
}
