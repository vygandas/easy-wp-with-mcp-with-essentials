# AGENTS.md

Working notes for AI agents (and humans) editing a site built from this template. [README.md](README.md) is the public overview; this file is the how and the why.

## House rules

- Write every piece of reader-facing prose with the `humanize` skill (`.claude/skills/humanize`).
- SEO has to pass clean: no red or orange Yoast checks on anything you publish.
- Production keeps a 100 Lighthouse score. Anything that adds render-blocking work, third-party script on load, or layout shift needs a reason.
- When proposing or exploring content and design with the OpenSpec skills, look at how real competitors present the same thing, and aim to do better than them.
- Don't fill content blocks with whatever happens to be in the media library. Leave clearly marked placeholder images for the owner to replace.

## Editing workflow

Prefer the WordPress MCP server for anything WordPress can store as configuration: content, Global Styles, typography, templates, template parts, navigation and plugin settings. Discover the available tools first, read the current state, change only what was asked, and verify the saved result.

Theme or plugin file changes are for runtime behaviour, infrastructure, or a limit the configuration path really can't express. A missing MCP tool alone doesn't make a theme override the right answer.

WordPress configuration lives in the database. It survives code deployments and is separate from git, so keep backups or exports. `docker compose down -v` deletes the local database; an ordinary deploy never touches the production one. Don't reset Site Editor customizations just so theme files win.

## Stack

- WordPress 7.1 vendored at the repository root, which is the web root. Core is updated by replacing its files from the release zip. Never edit `wp-admin/`, `wp-includes/` or the root core files.
- One `Dockerfile` (php:8.4-apache, WP-CLI) for local and production. `docker/entrypoint.sh` enforces mpm_prefork (some platform builders resurrect mpm_event), rewrites Apache's `Listen` to `$PORT`, and in production turns off OPcache timestamp checks and adds HSTS for requests that arrived over HTTPS.
- Apache hardening in `docker/apache/wordpress.conf` applies everywhere: `xmlrpc.php`, `readme.html`, `license.txt`, `wp-config-sample.php` and dotfiles return 403, PHP never executes from uploads, `ServerTokens Prod`, plus `nosniff` and `Referrer-Policy`. `docker/apache/mpm_prefork.conf` caps Apache at `APACHE_MAX_REQUEST_WORKERS` children.
- No `package.json`, no build step, no Node stage.

## Local environment

`powershell -File docker/start.ps1` creates `.env` with unique salts, starts Docker, fetches the external plugins, and installs WordPress only when the database is empty. Rerunning it preserves data. See [LOCAL-DEVELOPMENT.md](LOCAL-DEVELOPMENT.md).

Services in `docker-compose.yml` (project name `easy-wp`):

- `wordpress`: built from the `Dockerfile`, with the repo bind-mounted at `/var/www/html`, so edits are live. `.env` is therefore inside the web root, which is why Apache denies dotfiles.
- `caddy`: TLS with Caddy's internal CA at `https://localhost:8443`. It also answers to `easy-wp.test` if you add that to your hosts file. Caddy sets `X-Forwarded-Proto`, which is how WordPress knows it is on HTTPS.
- `db`: MySQL 8.4 in a named volume, reachable from desktop clients at `127.0.0.1:3307`.
- `minio` and `minio-init`: a local S3-compatible bucket standing in for production media. WordPress writes to `http://minio:9000`; browsers load media through Caddy at `https://localhost:9443/wp-uploads/...`. Console at `http://127.0.0.1:9101` with `S3_UPLOADS_KEY` / `S3_UPLOADS_SECRET` from `.env`.

All published ports bind to `127.0.0.1`, so the local auto-login can't be reached from the LAN. Keep it that way.

```bash
docker compose up -d --build              # first run, or after Dockerfile / docker/* changes
docker compose up -d                      # also after editing .env: containers only see env changes when recreated
docker compose logs -f wordpress
docker compose down                       # -v also wipes the database, media and certificate volumes
docker compose exec wordpress wp <cmd>    # WP-CLI as root (WP_CLI_ALLOW_ROOT is set)
docker compose exec db mysql -uroot -proot wordpress
```

Until the local CA is trusted (`docker/trust-local-ca.ps1` on Windows; the macOS command is in the README), automated browsers refuse `https://localhost:8443`. `curl -k` still works for non-visual checks. Trusting a CA edits the user's certificate store, so the user runs that step, not the agent.

Rehearse production behaviour locally with a one-off container:

```bash
docker compose run --rm --no-deps -e WP_ENVIRONMENT_TYPE=production -e PORT=9090 -p 127.0.0.1:9090:9090 wordpress
```

## Configuration model

- `wp-config.php` is committed and holds no secrets. Every value comes from environment variables through `site_env()` and `site_env_bool()`. Locally Compose injects `.env`; in production they come from the host's environment settings. Never hardcode a value in `wp-config.php`; add a variable to `.env.example` instead.
- Database: `MYSQL_URL` or `DATABASE_URL` (`mysql://user:pass@host:port/db`), or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`.
- `WP_HOME` is the canonical URL, port included locally. `WP_SITEURL` defaults to it and `FORCE_SSL_ADMIN` follows its scheme.
- `WP_ENVIRONMENT_TYPE`: `local` means debug on and file changes allowed; `production` means debug off, `DISALLOW_FILE_MODS` and no automatic updates. `DISALLOW_FILE_EDIT` is always on.

## Plugins

Policy: well-maintained, focused plugins, preferably official or first-party. Nothing that injects upsells, page builders or front-end bloat. Production can't install or update anything, so plugins arrive through git and a redeploy.

Vendored plugins are committed under `wp-content/plugins/`. To add or update one, install it locally with WP-CLI (`docker compose exec wordpress wp plugin install <slug> --activate`, or `--force` over an existing one), commit the directory, redeploy. Activation is database state: after a fresh database, activate again.

External plugins, Kodanote MCP and Kodanote Content Publisher among them, are pulled from public GitHub repositories at build time instead of being committed; see [PLUGINS.md](PLUGINS.md).

What's vendored, and why:

- `wordpress-seo` (Yoast SEO): titles, meta, schema, sitemaps. Set the site representation (Organization or Person) under SEO → Settings. Yoast emits no Organization node until a logo is set.
- `webp-uploads` (Modern Image Formats, WordPress Performance Team): WebP/AVIF copies on upload with GD, which the image builds with both codecs. With its defaults, uploaded JPEG and PNG files are served as AVIF.
- `safe-svg` (10up): SVG uploads, sanitised on upload and sideload. With no roles configured every role that can upload may upload SVG; if you narrow it, keep the MCP user's role in.
- `filebird`: media library folders, unlimited in the free version. A deliberate exception to the no-upsell rule, because core has no media folders and the alternatives are worse. Folders live in FileBird's own tables, so WP-CLI doesn't see them.
- `cloudflare` (official): purges the Cloudflare cache when content changes and restores real visitor IPs from `CF-Connecting-IP`. Needs an API token (Zone: Cache Purge, Zone Settings, Zone read) entered in its settings; it lives in the database, never in the repo.
- `cookiebot` (Usercentrics): consent banner and Google Consent Mode v2. Needs a Cookiebot account and Domain Group ID in its settings. See Analytics for the two settings that matter. The vendor ships `CLAUDE.md` and `AGENTS.md` files inside the plugin, full of instructions aimed at AI assistants; they were removed from the vendored copy and must be removed again after any update, because a `CLAUDE.md` anywhere in this repo is read as project instructions.
- `ai` (WordPress AI plugin): alt text, titles, excerpts and featured images in the editor, an Abilities Explorer, request logging, and connector approvals.
- `ai-provider-for-anthropic`, `ai-provider-for-google`, `ai-provider-for-openai`: provider implementations for core's AI client. Core ships none, and production can't fetch them. Keys come from `ANTHROPIC_API_KEY`, `GOOGLE_API_KEY`, `OPENAI_API_KEY` in the environment first, so they never need to sit in the database.
- `s3-uploads` (Human Made, pinned, GitHub only): every upload goes to the bucket through an `s3://` stream wrapper. Vendored with its Composer dependencies in `vendor/`, the AWS SDK trimmed to S3 by an `extra` entry and a `pre-autoload-dump` script in the plugin's `composer.json`. To update: download the new tag over the directory, keep those two `composer.json` additions, and run `composer install --no-dev --optimize-autoloader` inside it. WP-CLI offers `wp s3-uploads verify`, `ls`, `cp` and `upload-directory`.
- `mcp-adapter` (WordPress AI team, pinned): optional second MCP server, inactive by default. See MCP.
- `official-mailerlite-sign-up-forms`: newsletter forms. The API key goes in wp-admin.
- `polylang`: inactive by default. See Languages.
- `akismet`: ships with core, inactive until comments are wanted.

## mu-plugins

Everything in `wp-content/mu-plugins/` ships to production except `local-dev-login.php`, which `.dockerignore` keeps out of the image.

- `site-media-storage.php`: loads S3 Uploads' vendored AWS SDK, applies endpoint, path style and ACL policy, and shows a red admin notice in production when the bucket isn't configured or the plugin is inactive.
- `site-content-styles.php` with `site-content.css`: article-body styling on every front-end view, scoped to the post-content block. It gives content section rhythm, link colour and treatment for quotes, tables, code and images using only theme presets, so pasted plain HTML reads the same as block content. It also styles `div.key-takeaways` and `div.table-of-contents` boxes.
- `site-post-list.php`: Twenty Twenty-Five's `template-query-loop` pattern prints every post's full content, which turns the Blog page and every archive into a wall of complete articles. This re-registers that pattern slug with an excerpt list (date, title, 40-word excerpt, pagination), also available in the inserter as `site/post-list`. Its two reader-facing strings are registered with Polylang when it is active (group "Site").
- `site-defaults.php`: comments, pingbacks and trackbacks are off site-wide through filters, comment feeds included. The Discussion settings screen shows values but can't change them; edit the file instead.
- `site-gtm.php`, `site-polylang.php`: see Analytics and Languages.
- `site-abilities.php`, `site-polylang-abilities.php`: WordPress Abilities for the optional MCP Adapter route, see MCP.

## Analytics

`site-gtm.php` prints the Google Tag Manager container. No plugin does it: GTM4WP and GTM Kit are fine, but most of their weight is WooCommerce dataLayer handling, and two script tags plus a `dataLayer` push don't need a plugin.

| Variable | Meaning |
| --- | --- |
| `GTM_CONTAINER_ID` | `GTM-XXXXXXX`. Empty (the default, and the right local value) prints nothing. Anything not matching the format is ignored. |
| `GTM_LOAD_STRATEGY` | `interaction` (default) or `immediate`. |
| `GTM_LOAD_DELAY` | Fallback timer in ms for `interaction`. `0` (default) waits for interaction only. |
| `GTM_CONSENT_DEFAULTS` | `true` emits Consent Mode v2 denied-by-default ahead of the container. Default `false`. |

`interaction` is why this is a file and not a plugin. Loading `gtm.js` the normal way costs LCP and TBT and gets the site named in Lighthouse's third-party diagnostic. The loader waits for the first `pointerdown`, `keydown`, `wheel`, `touchstart`, `mousemove` or `scroll`. Lighthouse does none of those, so the container never enters the trace, while real visitors trigger it almost immediately. Sessions where nobody interacts at all go unreported; `GTM_LOAD_DELAY=3500` buys those back at the risk of `gtm.js` landing in the trace on a slow page.

The dataLayer carries `pageType`, plus `postID`, `postType`, `postTitle`, `postDate`, `postAuthor`, `postCategories` and `postTags` on single views, and `userLoggedIn` with `userRole` so GTM triggers can exclude staff traffic. Nothing identifies a person. Values are encoded with `JSON_HEX_TAG`, so a title containing a closing script tag can't break out.

Filters: `site_gtm_container_id`, `site_gtm_should_load`, `site_gtm_data_layer`, `site_gtm_consent_defaults`.

Consent belongs to Cookiebot, and the two must not overlap:

- **Leave Cookiebot's Google Tag Manager tab off.** Enabled, it prints the stock GTM snippet early in the head, so the container loads twice, every event fires twice, and the deferred loading stops meaning anything.
- **Leave Cookiebot's Google Consent Mode tab on** (its default). It emits the `consent default` command with every signal denied and owns the `update` call when a visitor chooses. That is exactly what `GTM_CONSENT_DEFAULTS` would write, so the variable stays `false`, and `site_gtm_cookiebot_owns_consent()` suppresses ours anyway whenever Cookiebot is emitting. That check mirrors Cookiebot's own emitter condition (the raw `cookiebot-gcm` option being neither `false` nor `''`) rather than asking whether the feature "reads as enabled", because the two differ.

When Cookiebot is active the loader tag gets `data-cookieconsent="ignore"`, so Cookiebot's automatic blocking doesn't stop the loader itself. The container loads and Consent Mode governs what its tags may do.

Consent Mode v2 depends on where visitors are, not where the company is: EEA and UK traffic needs it if Google Ads runs against that traffic.

## Media storage

The container filesystem is ephemeral: anything uploaded to its disk disappears on the next deploy. Uploads therefore go to an S3-compatible bucket through `s3-uploads`, configured only by environment variables (table in the README). Locally the `.env.example` defaults point at MinIO, so nothing extra is needed.

- Objects are stored with `Cache-Control: max-age=31536000`, which is safe because WordPress never reuses a filename.
- `wp-config.php` strips a trailing `/<bucket>` from `S3_UPLOADS_ENDPOINT`. R2's dashboard shows the endpoint with the bucket appended, and with it every key gets a doubled `bucket/` prefix and uploads hang.
- Cloudflare caches 404s for a few minutes by default. On the media domain that becomes blank media-library tiles whenever a size is requested before WordPress has written it, so add a Cache Rule for the media host that doesn't cache 404s.
- Buckets that only issue presigned links (Railway Storage Buckets, for example) can't serve website images.
- Cloudflare R2: endpoint `https://<account-id>.r2.cloudflarestorage.com`, region `auto`, ACL `none`, an API token scoped to Object Read & Write on the bucket, and a custom domain for `S3_UPLOADS_BUCKET_URL`.
- AWS S3: endpoint empty, path style `false`, and either bucket ACLs with `public-read` or a public-read bucket policy plus ACL `none`.

Activate the plugin once per environment (allowed under `DISALLOW_FILE_MODS`), then `wp s3-uploads verify`. Files already on a disk can be pushed with `wp s3-uploads upload-directory /var/www/html/wp-content/uploads uploads`. Deleting an attachment deletes its objects.

## MCP

The MCP server is **Kodanote MCP**, an external plugin (see PLUGINS.md) that runs inside WordPress. No separate Node server or identity provider is involved.

- Endpoint: `https://<domain>/wp-json/kodanote-mcp/v1/mcp`. Needs HTTPS and pretty permalinks.
- Auth: its own OAuth flow with PKCE and dynamic client registration. A client signs in through WordPress login and a consent screen where the user picks individual permissions. Access tokens last an hour, refresh tokens rotate on every use, and changing the WordPress password revokes existing authorizations.
- Access is the intersection of the scopes the user approved and the user's current WordPress capabilities, checked on every request. Tools the account can't use are hidden from `tools/list`. Give the connecting account the least role the task needs: Editor for content and media, Administrator for templates, global styles, navigation and site settings. Don't create users or change roles automatically.
- Tools cover content (list/get/create/update/trash, terms), media, appearance (theme, Global Styles including custom CSS, templates, template parts, layout, navigation), selected site settings, plugin and user inventories, and an audit log with undo for supported changes. New content defaults to draft. Optional `seo` fields write through Yoast.
- Connections are listed and revocable under **Users → MCP Connections** (or **Profile → MCP Connections**), writes are recorded under **Tools → MCP Audit Log**.
- Hosted connectors, such as a custom connector in Claude, need a publicly reachable HTTPS site. They can't reach the local stack.
- The plugin's README in its repository is the reference for every tool and scope.

### Optional: MCP Adapter and `site-abilities.php`

The official WordPress MCP Adapter is still vendored, inactive. Activate it only if a client must authenticate with an Application Password instead of OAuth. It serves `https://<domain>/wp-json/mcp/mcp-adapter-default-server` with three meta tools (`mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, `mcp-adapter-execute-ability`) over the abilities that `site-abilities.php` registers: `site/describe-content-model`, `list-content`, `get-content`, `create-content`, `update-content`, `trash-content` (no permanent delete, on purpose), `list-terms`, `create-term`, `sideload-media`. Those abilities are brand-neutral and discover post types, taxonomies and the SEO plugin (Yoast, Rank Math or SEOPress) at runtime; filters `site_abilities_post_types`, `site_abilities_taxonomies` and `site_abilities_seo_provider` narrow or extend them. Register new ones on `wp_abilities_api_init` with the narrowest `permission_callback`, `meta.public => true` and honest `annotations`.

## Deployment

The template assumes no particular host. Anything that builds the `Dockerfile` and runs the container works.

- Same `Dockerfile` as local. `.dockerignore` keeps local-only files (Compose, Caddy, PowerShell helpers, `.env`, the auto-login, docs, agent tooling) out of the image.
- Requirements: MySQL 8, an S3-compatible bucket, and a TLS-terminating proxy that sets `X-Forwarded-Proto`. `wp-config.php` trusts that header, so the container must only be reachable through the proxy.
- The container listens on `$PORT` when it is set, otherwise on 80. Health check: `GET /healthz.php`, which loads WordPress with `SHORTINIT` and returns 200 only when the database answers.
- Variables: `WP_ENVIRONMENT_TYPE=production`, `WP_HOME=https://<domain>`, the database (`MYSQL_URL` / `DATABASE_URL`, or `DB_*`), eight salts (fresh ones, never the local set; `openssl rand -hex 32` per key), the `S3_UPLOADS_*` set, and optionally `APACHE_MAX_REQUEST_WORKERS` (default 10, sized for 1 to 2 GB of RAM).
- One replica is enough to start. With media in the bucket, nothing ties the site to a single container.
- Cloudflare in front: SSL/TLS mode Full or Full (strict), never Flexible, or the HTTPS detection in `wp-config.php` and the host's own HTTPS redirect loop. Leave Rocket Loader and Auto Minify off, or exclude `/wp-admin/*`; both break the block editor. If the WAF ever challenges the REST API or the MCP endpoint, add a rule that skips managed challenges for `/wp-json/*` requests carrying an `Authorization` header.
- Nothing may be written at runtime: plugins, themes and core arrive only through git and a rebuild. `.htaccess` is committed for the same reason.
- WP-CLI is in the image. Run it in the live container with `docker exec` on your own server, or through your platform's shell feature.
- Railway: `railway.json` states the service settings (Dockerfile builder, one replica, restart on failure, health check `/healthz.php`). Railway has deprecated config-as-code for new services, so apply settings in the dashboard if it is ignored. `powershell -File docker/railway-bootstrap.ps1 -SiteUrl https://<domain> -SkipVolume` sets the production variables, generating salts without printing them; don't let it add a volume. Useful CLI: `railway status` (run it first), `railway link`, `railway up`, `railway logs`, `railway variable`, `railway redeploy`, and `railway ssh` for a shell with WP-CLI.

## Theme: `wp-content/themes/site`

An empty child of Twenty Twenty-Five. Activating it adds one stylesheet and the `wp-child-theme-site` body class and otherwise renders exactly like the parent. `theme.json` has empty `settings` and `styles`, so the parent's palette, font sizes and spacing merge through untouched. Read its [README](wp-content/themes/site/README.md) before changing anything there.

Prefer MCP for templates and template parts. Site Editor customizations are database records that take precedence over theme files; read and preserve them before any code change, and don't use *Template → Reset* without a specific reason.

If a task genuinely needs theme code:

- Block theme. Use Global Styles for configurable design and keep `theme.json` for code-level defaults.
- Tailwind is optional. If it is ever justified, use v4 with CSS-first configuration compiled by `@tailwindcss/cli` into one stylesheet under `assets/dist/` (git-ignored), with no preflight (it fights WordPress's base and block styles), and theme tokens pointing at `var(--wp--preset--*)`. Adding it means adding a Node build stage to the `Dockerfile` in the same change.
- Vanilla CSS per block through `wp_enqueue_block_style()`, so it loads only where the block renders.
- No jQuery, no JS frameworks. Interactivity API and `viewScriptModule` where a block needs behaviour.
- One CSS file, no render-blocking JS, self-hosted woff2 or a system font stack.

## Languages (optional): Polylang

Polylang is vendored and inactive. Before activating it:

1. Create the default language first; creation order decides the default. Check with `wp eval 'echo pll_default_language();'`.
2. URL settings (Languages → Settings → URL modifications): language code in the directory name, default language hidden, `/lt/` rather than `/language/lt/`, browser detection **off**. Detection on makes `/` redirect with `Vary: Accept-Language`, and Cloudflare ignores that header, so the first visitor's redirect would be served to everyone. `site-polylang.php` sets `PLL_COOKIE` to `false` for the same caching reason; turning detection on means deleting that define in the same change.
3. Leave media translation off. It duplicates attachment rows without duplicating files.
4. Assign every existing post, page and term to the default language before adding a second one (`wp eval 'PLL()->model->set_language_in_mass();'`). Content with no language drops out of archives and sitemaps. Count sitemap URLs before and after.
5. Flush rewrites with `wp rewrite flush`, never `--hard` (it tries to write `.htaccess` on an ephemeral filesystem).
6. Translate the front page and the Blog page before linking any other language, or the language's root renders an empty, indexable post list.

What the code does once it's on:

- **hreflang** is Polylang's job; Yoast free emits none. Polylang only emits `x-default` on the front page and only while the default code is visible in URLs, which this scheme hides, so `site-polylang.php` supplies `x-default` on every translated view through `pll_rel_hreflang_attributes` (filter: `site_polylang_x_default`).
- **dataLayer** gets `contentLanguage` (the language slug) through `site_gtm_data_layer`, because GA4's own `language` is the browser's setting, not the page's.
- **MCP Adapter route**: `site-polylang-abilities.php` widens the `site/*` abilities with `language` and `translation_of`, stamping the language on `save_post` priority 9 so terms land in the right language. Whoever edits `site-abilities.php` must read it first: the trick depends on the insert still happening before terms are applied. Kodanote MCP's own tools don't know about languages.
- **Yoast**: leave the "Website name" field empty (it isn't translatable, the site title is), set and translate the tagline, and rewrite the SEO fields of translations created in wp-admin, because Polylang copies the original's title, description and focus keyphrase and Yoast scores the copy green.
- **Cookiebot**: set its Language to "Use WordPress Language" so the banner follows the page. The shipped "Default (Autodetect)" emits no `data-culture` and the language is guessed off-site. Every language must exist in the Cookiebot Manager, and the free plan's subpage limit counts all of them.
- `/en/anything/` returns 404, not a redirect, while the default language is hidden. That is correct. If such URLs are ever linked from outside, redirect them with a Cloudflare rule.

## Conventions

- Everything under `wp-content/` except `uploads/` and the fetched external plugins is code and is committed.
- Files are LF; `.gitattributes` enforces it because shell scripts and the Caddyfile run inside Linux containers.
- The helper scripts in `docker/` are PowerShell (Windows). On macOS or Linux, run the equivalent commands by hand; the README has them.
- In zsh, unquoted variables don't word-split, so `FILES="a b c"` followed by `cmd $FILES` passes one long filename. Use an array: `FILES=(a b c)` and `"${FILES[@]}"`.

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:970c3bf2 -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

**Architecture in one line:** issues live in a local Dolt DB; sync uses `refs/dolt/data` on your git remote; `.beads/issues.jsonl` is a passive export. See https://github.com/gastownhall/beads/blob/main/docs/SYNC_CONCEPTS.md for details and anti-patterns.

## Agent Context Profiles

The managed Beads block is task-tracking guidance, not permission to override repository, user, or orchestrator instructions.

- **Conservative (default)**: Use `bd` for task tracking. Do not run git commits, git pushes, or Dolt remote sync unless explicitly asked. At handoff, report changed files, validation, and suggested next commands.
- **Minimal**: Keep tool instruction files as pointers to `bd prime`; use the same conservative git policy unless active instructions say otherwise.
- **Team-maintainer**: Only when the repository explicitly opts in, agents may close beads, run quality gates, commit, and push as part of session close. A current "do not commit" or "do not push" instruction still wins.

## Session Completion

This protocol applies when ending a Beads implementation workflow. It is subordinate to explicit user, repository, and orchestrator instructions.

1. **File issues for remaining work** - Create beads for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **Handle git/sync by active profile**:
   ```bash
   # Conservative/minimal/default: report status and proposed commands; wait for approval.
   git status

   # Team-maintainer opt-in only, unless current instructions forbid it:
   git pull --rebase
   bd dolt push
   git push
   git status
   ```
5. **Hand off** - Summarize changes, validation, issue status, and any blocked sync/commit/push step

**Critical rules:**
- Explicit user or orchestrator instructions override this Beads block.
- Do not commit or push without clear authority from the active profile or the current user request.
- If a required sync or push is blocked, stop and report the exact command and error.
<!-- END BEADS INTEGRATION -->

<!-- BEGIN BEADS CODEX SETUP: generated by bd setup codex -->
## Beads Issue Tracker

Use Beads (`bd`) for durable task tracking in repositories that include it. Use the `beads` skill at `.agents/skills/beads/SKILL.md` (project install) or `~/.agents/skills/beads/SKILL.md` (global install) for Beads workflow guidance, then use the `bd` CLI for issue operations.

### Quick Reference

```bash
bd ready                # Find available work
bd show <id>            # View issue details
bd update <id> --claim  # Claim work
bd close <id>           # Complete work
bd prime                # Refresh Beads context
```

### Rules

- Use `bd` for all task tracking; do not create markdown TODO lists.
- Run `bd prime` when Beads context is missing or stale. Codex 0.129.0+ can load Beads context automatically through native hooks; use `/hooks` to inspect or toggle them.
- Keep persistent project memory in Beads via `bd remember`; do not create ad hoc memory files.

**Architecture in one line:** issues live in a local Dolt DB; sync uses `refs/dolt/data` on your git remote; `.beads/issues.jsonl` is a passive export. See https://github.com/gastownhall/beads/blob/main/docs/SYNC_CONCEPTS.md for details and anti-patterns.
<!-- END BEADS CODEX SETUP -->
