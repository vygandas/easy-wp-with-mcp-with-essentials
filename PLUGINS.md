# Plugins

Plugins reach this site in two ways.

**Vendored.** Committed under `wp-content/plugins/` at a known version. Everything from wordpress.org (Yoast, Cookiebot, Polylang and the rest) and a few GitHub-only plugins (S3 Uploads, MCP Adapter) work this way. AGENTS.md says why each one is here and how to update it.

**External.** Pulled from a public GitHub repository at build time and never committed. `docker/external-plugins.txt` is the list:

```text
# Kodanote MCP 0.5.0
kodanote-mcp                Solidmatics/kodanote-wp-mcp                e50f66c888873e2dd8bf2caa3d16236f6e645c38
# Kodanote Content Publisher 1.3.113
kodanote-content-publisher  Solidmatics/kodanote-wp-content-publisher  68c6ac01ac4db3f28f759557f8ee4dadf5fe74be
```

Each line is the folder name under `wp-content/plugins`, the GitHub `owner/repo`, and a ref (branch, tag or commit SHA). `docker/fetch-plugins.sh` downloads the archive from GitHub, unpacks it into that folder, and strips `tests`, `scripts`, `dist` and `.github`. No credentials are sent, so the repositories must be public.

## Where the fetch runs

- **Docker build.** A separate build stage runs the script, and the final image copies the result into `wp-content/plugins`. That's what gets deployed.
- **Local stack.** The repository is bind-mounted over `/var/www/html`, which hides anything the image put there, so `docker/start.ps1` runs the script inside the container as well. It writes into your working tree and skips plugins that are already present. To update one locally, delete its folder and rerun the start script, or run the script yourself:

  ```bash
  docker compose exec wordpress sh docker/fetch-plugins.sh docker/external-plugins.txt wp-content/plugins
  ```

The fetched folders are listed in `.gitignore`, `.dockerignore` and `.railwayignore`, so a local copy is never committed and never shipped in place of the one the build downloads.

## Pinned versions

Both plugins are pinned to a commit SHA, with the version in the comment above each line. Docker caches the fetch stage until `docker/external-plugins.txt` or the script changes, so with a branch like `main` a deploy could keep serving an old download without telling you. A pinned SHA can't drift, and bumping it is an edit to the list, which also invalidates the cache.

To update a plugin, take the commit you want from its repository, for example with `git ls-remote https://github.com/Solidmatics/kodanote-wp-mcp.git refs/heads/main`, replace the SHA and the version comment, and redeploy. Locally, delete the plugin's folder under `wp-content/plugins` and rerun `docker/start.ps1`. A tag works in place of a SHA once the repositories publish tags.

## Add or remove a plugin

1. Add a line to `docker/external-plugins.txt`, or delete one.
2. Add the folder to `.gitignore` (with a trailing slash), `.dockerignore` and `.railwayignore`, or take it out.
3. Rerun `docker/start.ps1` locally. It fetches new entries and activates everything on the list.
4. Commit and deploy, then activate the plugin in wp-admin on production. Activation is a database setting, so nothing activates at container start.

## Working on an external plugin

Edit it in its own repository, not in the fetched copy here: `fetch-plugins.sh` replaces the folder wholesale. To try unreleased changes against this site, check the plugin repository out into `wp-content/plugins/<folder>` (the folder is git-ignored, and `SKIP_EXISTING` leaves it alone), test through the local stack, then push from the plugin's repository.

## The two default plugins

**Kodanote MCP** is the site's MCP server: OAuth with PKCE, role-aware tools for content, reusable patterns, media, appearance and settings, and an audit log with undo. Activation creates its tables and cleanup schedule. Connections live under **Users → MCP Connections**. Its README has the full tool and scope reference.

**Kodanote Content Publisher** lets the Kodanote service sync, schedule and publish articles to the site. It records the activating user as the default publishing author, which is why the start script activates external plugins as the first administrator. Its outbound API URL is empty by default. When there are real service URLs to point at, define them before plugins load, through the environment like everything else in `wp-config.php`:

```php
define( 'KODANOTE_API_BASE_URL', site_env( 'KODANOTE_API_BASE_URL', '' ) );
define( 'KODANOTE_DASHBOARD_URL', site_env( 'KODANOTE_DASHBOARD_URL', 'https://www.kodanote.com' ) );
```

The per-site API key goes in the plugin's settings screen and stays in the database.

Don't need one of them? Delete its line and its ignore entries, and it's gone from the next build.
