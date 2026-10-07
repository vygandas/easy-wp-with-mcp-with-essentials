# Local development

Docker Compose runs WordPress on PHP 8.4 with MySQL 8.4, Caddy for HTTPS, and a MinIO bucket for media. The repository is bind-mounted into the container: edit a PHP, JS or CSS file, reload the page, done. Only changes to the `Dockerfile` or `docker/` image config need a rebuild.

## Start on Windows

Start Docker Desktop, then from the repository root:

```powershell
powershell -File docker/start.ps1 -Build
```

The script:

- creates the git-ignored `.env` if it's missing, with unique WordPress salts and a local login token;
- refuses to run if the configuration points anywhere but the local Compose database, or exposes a port beyond `127.0.0.1`;
- starts the containers and downloads the plugins listed in `docker/external-plugins.txt` into `wp-content/plugins` (present ones are kept);
- installs WordPress only when the database is empty, with pretty permalinks, search engines discouraged, the `site` theme, and S3 Uploads, Yoast, Safe SVG and Modern Image Formats active;
- activates the external plugins as the first administrator.

Rerunning it keeps your content, settings and credentials. Drop `-Build` on later runs unless the image changed.

Trust the local HTTPS certificate once. This edits your Windows user's certificate store, so run it yourself and accept the prompt:

```powershell
powershell -File docker/trust-local-ca.ps1
```

It covers both the site and local media. Then open wp-admin already signed in:

```powershell
powershell -File docker/login.ps1
```

The login helper uses `LOCAL_LOGIN_TOKEN` from `.env`, so no password is involved. The install generated a random admin password that is never printed or stored; reset it with WP-CLI if you ever need one.

## macOS and Linux

The helpers are PowerShell. Run the equivalent steps by hand; the README lists them, including the macOS command to trust Caddy's CA.

## Services

| Service | Address |
| --- | --- |
| Website | https://localhost:8443/ |
| WordPress admin | https://localhost:8443/wp-admin/ |
| MCP endpoint (Kodanote MCP) | https://localhost:8443/wp-json/kodanote-mcp/v1/mcp |
| MCP connections | https://localhost:8443/wp-admin/users.php?page=kodanote-mcp |
| MCP audit log | https://localhost:8443/wp-admin/tools.php?page=kodanote-mcp-audit |
| Media (through Caddy) | https://localhost:9443/wp-uploads/ |
| MinIO console | http://127.0.0.1:9101/ |
| MySQL for desktop clients | `127.0.0.1:3307` |

These are the `.env.example` ports. If you change one, update the matching `WP_HOME` or `S3_UPLOADS_BUCKET_URL` and rerun the start script. MinIO and database credentials are in your `.env`.

A hosted MCP connector can't reach `localhost`. To test a connector end to end you need a publicly reachable HTTPS copy of the site. Local MCP clients work if they trust Caddy's certificate.

## Large cookies on localhost

Browsers share `localhost` cookies across ports, so other local apps can push WordPress's `Cookie` header past Apache's default 8190-byte limit and you get "Size of a request header field exceeds server limit". The local stack raises the limit to 64 KiB. That override only applies when both `WP_ENVIRONMENT_TYPE=local` and Compose's `WP_LOCAL_STACK=1` marker are set, so production keeps the normal limit. Rebuild with `docker/start.ps1 -Build` if an older image still shows the error.

## Everyday commands

```powershell
# Start again; installs only if the database is empty.
powershell -File docker/start.ps1

# Rebuild after changing the Dockerfile or image-level PHP/Apache config.
powershell -File docker/start.ps1 -Build

# Look around.
docker compose ps
docker compose logs --tail=100 wordpress caddy
docker compose exec wordpress wp plugin list

# Stop, keeping the database and media volumes.
docker compose down
```

Adding `-v` to `docker compose down` erases the local database, media and certificate volumes. Only do that on purpose. With debugging on, PHP notices also go to the git-ignored `wp-content/debug.log`.

The local database is separate from production. Pages, Global Styles, users and plugin settings live in the database and are never copied between the two.

The start, login and CA helpers stay out of the production image (see `.dockerignore`), and none of them touches production.
