# Easy WP with MCP

A WordPress 7.1 starter that runs the same Docker image on your laptop and on whatever container host you deploy to. It comes with an MCP server, so an AI agent such as Claude can write posts and change the design through WordPress itself instead of SSH or a pile of copied HTML. Media lives in an S3-compatible bucket, so a redeploy never wipes your images.

The repository root is the web root. WordPress core is vendored, not installed through Composer, and every plugin is committed at a known version.

## What's inside

**Runtime.** PHP 8.4 with Apache and mod_php in one container, WP-CLI baked in, GD built with WebP and AVIF. Production behaviour (OPcache without timestamp checks, HSTS) switches on from `WP_ENVIRONMENT_TYPE`, so what you test locally is the image you ship.

**Local stack.** MySQL 8.4, Caddy terminating HTTPS at `https://localhost:8443`, and MinIO standing in for the media bucket. Every port binds to `127.0.0.1`.

**MCP.** The [Kodanote MCP](https://github.com/Solidmatics/kodanote-wp-mcp) plugin runs the MCP server inside WordPress: OAuth consent, role-aware tools for content, reusable patterns, media, Global Styles, templates, navigation and settings, plus an audit log with undo. It is pulled from its public GitHub repository at build time, see [External plugins](#external-plugins).

**Plugins** (committed, pinned): Yoast SEO, Modern Image Formats (WebP/AVIF on upload), Safe SVG, FileBird media folders, the official Cloudflare plugin, Cookiebot consent with Google Consent Mode v2, the WordPress AI plugin with the Anthropic, Google and OpenAI providers, MailerLite forms and S3 Uploads. Polylang, Akismet and the WordPress MCP Adapter ship inactive.

**mu-plugins** (always on, no settings screens):

| File | Does |
| --- | --- |
| `site-media-storage.php` | Wires S3 Uploads to your bucket and shouts in wp-admin when production has no bucket. |
| `site-gtm.php` | Google Tag Manager that loads on the visitor's first interaction, so Lighthouse never sees it. |
| `site-content-styles.php` | Readable article typography for post content, including pasted plain HTML. |
| `site-post-list.php` | Blog and archive pages list excerpts instead of printing every full post. |
| `site-defaults.php` | Turns comments, pingbacks and trackbacks off site-wide. Edit the file to change that. |
| `site-polylang.php` | x-default hreflang, a `contentLanguage` dataLayer value, no language cookie. Idle without Polylang. |
| `site-abilities.php`, `site-polylang-abilities.php` | WordPress Abilities for the optional MCP Adapter route (Application Passwords instead of OAuth). |
| `local-dev-login.php` | Token-based auto-login for the local stack only. Never shipped in the image. |

**Reusable components.** WordPress synced patterns are built-in components: build a section once in the block editor, insert it on any page, and editing it later updates every page that uses it. Kodanote MCP can list, read, create and update them, and a **Used in** column and editor panel show where each one is used before you change it.

**Theme.** `wp-content/themes/site`, an empty child of Twenty Twenty-Five. It renders exactly like the parent until you change something. Design changes are meant to go through Global Styles and the Site Editor (by hand or over MCP), not theme files.

## Run it locally

You need Docker Desktop. On Windows:

```powershell
powershell -File docker/start.ps1 -Build      # creates .env, starts the stack, installs WordPress if the DB is empty
powershell -File docker/trust-local-ca.ps1    # once: trust Caddy's local CA so the browser stops warning
powershell -File docker/login.ps1             # opens wp-admin signed in, no password involved
```

The start script is safe to rerun. It keeps existing content and settings, and it downloads the external plugins into `wp-content/plugins` if they are missing.

On macOS or Linux, run the steps by hand:

```bash
cp .env.example .env                 # then fill the eight salts and LOCAL_LOGIN_TOKEN (openssl rand -hex 16)
docker compose up -d --build
docker compose exec wordpress sh docker/fetch-plugins.sh docker/external-plugins.txt wp-content/plugins
docker compose exec wordpress wp core install --url=https://localhost:8443 --title="My Site" \
  --admin_user=admin --admin_email=you@example.com --admin_password='choose-one' --skip-email
docker compose exec wordpress wp rewrite structure '/%postname%/'
docker compose exec wordpress wp theme activate site
docker compose exec wordpress wp plugin activate s3-uploads wordpress-seo safe-svg webp-uploads
docker compose exec wordpress wp plugin activate kodanote-mcp kodanote-content-publisher --user=admin
```

Then trust the local CA (macOS):

```bash
docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt docker/caddy-root.crt
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain docker/caddy-root.crt
```

More detail, including the local service map, is in [LOCAL-DEVELOPMENT.md](LOCAL-DEVELOPMENT.md).

## Deploy

Nothing here is tied to one host. Anything that builds a `Dockerfile` and runs the container will do: a PaaS such as Railway, Render, Fly.io or Coolify, or your own server running Docker behind a reverse proxy. You need:

- a MySQL 8 database;
- an S3-compatible bucket for media (see [Media storage](#media-storage));
- HTTPS in front. The platform's proxy or load balancer terminates TLS and sends `X-Forwarded-Proto`. WordPress trusts that header, so never expose the container to the internet directly.

Then:

1. Build from this repository's `Dockerfile`. The container listens on `$PORT` when the platform sets one, otherwise on 80.
2. Set the environment variables from [Configuration](#configuration): at least `WP_ENVIRONMENT_TYPE=production`, `WP_HOME`, the database, the `S3_UPLOADS_*` set and eight fresh salts. To generate the salts:

   ```bash
   for k in AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT; do echo "$k=$(openssl rand -hex 32)"; done
   ```

3. Point the platform's health check at `/healthz.php`. It loads WordPress with `SHORTINIT` and returns 200 only when the database answers.
4. Deploy, run the WordPress installer once, then activate plugins in wp-admin. Production can't install or update code (`DISALLOW_FILE_MODS`), but activation and settings work normally.
5. Behind Cloudflare, set SSL mode to Full or Full (strict), never Flexible, or you get a redirect loop. Keep Rocket Loader and Auto Minify off; both break the block editor.

The container's disk is disposable. Code, plugins and core change only through git and a rebuild, while content and design live in the database and media in the bucket, so both survive every deploy.

### On Railway

`railway.json` and `docker/railway-bootstrap.ps1` give Railway a ready-made setup:

1. Create a Railway project with a MySQL service, plus a service built from this repository.
2. From this checkout run `railway link`, then
   `powershell -File docker/railway-bootstrap.ps1 -SiteUrl https://www.example.com -SkipVolume`.
   It sets `WP_ENVIRONMENT_TYPE`, `WP_HOME`, `MYSQL_URL` and fresh salts without printing them. Keep `-SkipVolume`: media belongs in the bucket, not on a volume.
3. Set the `S3_UPLOADS_*` variables and deploy. `railway.json` already points the health check at `/healthz.php`.

## Connect an AI agent

Kodanote MCP serves the MCP endpoint with its own OAuth flow, so there are no Application Passwords or tokens to copy around:

```text
https://www.example.com/wp-json/kodanote-mcp/v1/mcp
```

1. Activate Kodanote MCP (the local start script does it for you) and open **Users → MCP Connections** to see the endpoint.
2. In Claude, add a custom connector with that URL and connect. You sign in to WordPress and tick the permissions you want to grant.
3. Ask for something: list drafts, change the palette, fix a template. The tools you see depend on the scopes you approved and your WordPress role, so an Editor can't touch site settings even if a scope says so.

Connections can be revoked from the same screen, and every write lands in **Tools → MCP Audit Log**, with undo for supported changes. A hosted connector needs a public HTTPS site; it can't reach your localhost.

If a client can only authenticate with an Application Password, activate the vendored WordPress MCP Adapter instead. It serves `/wp-json/mcp/mcp-adapter-default-server` with the abilities from `site-abilities.php`; AGENTS.md has the details.

## External plugins

`docker/external-plugins.txt` lists plugins that are pulled from public GitHub repositories instead of being committed here. The Docker build downloads them, and the local start script downloads them into the working tree too, because the bind mount hides what the image contains. Two are listed by default:

- [Kodanote MCP](https://github.com/Solidmatics/kodanote-wp-mcp): the MCP server described above.
- [Kodanote Content Publisher](https://github.com/Solidmatics/kodanote-wp-content-publisher): lets the Kodanote service sync and publish articles to the site.

Both are pinned to a commit, so every build gets the same code. Don't need one? Delete its line. Want your own? Add a line. [PLUGINS.md](PLUGINS.md) covers updating pins and the vendored plugins.

## Configuration

Everything comes from environment variables: `.env` locally, your host's environment settings in production. `wp-config.php` is committed and holds no secrets.

| Variable | Meaning |
| --- | --- |
| `WP_ENVIRONMENT_TYPE` | `local` turns debugging on and allows file changes. `production` turns debugging off, forbids file changes and disables automatic updates. |
| `WP_HOME` | Canonical URL, port included locally. `WP_SITEURL` follows it unless set. |
| `MYSQL_URL` or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Database. A single `mysql://user:pass@host:port/db` URL works as is. |
| `AUTH_KEY` … `NONCE_SALT` | The eight salts. WordPress refuses to boot without them. |
| `S3_UPLOADS_*` | Media bucket, see below. |
| `GTM_CONTAINER_ID`, `GTM_LOAD_STRATEGY`, `GTM_LOAD_DELAY`, `GTM_CONSENT_DEFAULTS` | Google Tag Manager. Empty container ID prints nothing. |
| `ANTHROPIC_API_KEY`, `GOOGLE_API_KEY`, `OPENAI_API_KEY` | Picked up by core's Connectors, so keys never sit in the database. |
| `APACHE_MAX_REQUEST_WORKERS` | Apache child limit, default 10 (sized for 1 to 2 GB of RAM). |

`.env.example` documents every local value.

## Media storage

Container disks on most hosts don't survive a redeploy, so uploads go to a bucket through [S3 Uploads](https://github.com/humanmade/S3-Uploads). Nothing is written to `wp-content/uploads`.

| Variable | Meaning |
| --- | --- |
| `S3_UPLOADS_BUCKET` | Bucket name, optionally `bucket/prefix`. Empty means files land on the container disk, which is only acceptable locally. |
| `S3_UPLOADS_REGION` | `auto` for Cloudflare R2, `us-east-1` for MinIO. |
| `S3_UPLOADS_KEY`, `S3_UPLOADS_SECRET` | Key pair with read, write and delete on the bucket. |
| `S3_UPLOADS_ENDPOINT` | The provider's S3 API URL without the bucket name. Empty for AWS S3. |
| `S3_UPLOADS_BUCKET_URL` | Public HTTPS base URL for media, ideally a custom domain on the bucket. |
| `S3_UPLOADS_PATH_STYLE` | `true` for MinIO, `false` for AWS and R2. |
| `S3_UPLOADS_OBJECT_ACL` | `public-read` by default; `none` for providers without per-object ACLs, such as R2. |

Cloudflare R2 is the easy pick if your DNS is already there: free egress and a cached custom domain such as `media.example.com`. Buckets that only hand out presigned links (Railway's, for example) can't serve website images. After setting the variables, activate S3 Uploads and run `wp s3-uploads verify`.

## Layout

```text
Dockerfile, docker-compose.yml   one image for local and production; compose adds MySQL, Caddy, MinIO
docker/                          entrypoint, Apache/PHP config, Caddyfile, helper scripts, plugin fetcher
wp-config.php                    reads everything from the environment
healthz.php                      health check endpoint
wp-content/mu-plugins/           the always-on code listed above
wp-content/plugins/              vendored plugins, plus the external ones once fetched
wp-content/themes/site/          empty child theme of Twenty Twenty-Five
AGENTS.md                        working notes for AI agents and humans editing this repo
```

## License

GPL-2.0-or-later, see [LICENSE](LICENSE). WordPress core and the bundled plugins carry their own GPL licenses; see `license.txt` and each plugin's files.
