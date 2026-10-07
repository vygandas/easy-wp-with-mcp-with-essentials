#!/usr/bin/env bash
set -euo pipefail

# Some platform builders (Railway's image stacker, for one) can resurrect files
# that the php:8.4-apache base image deleted, so mpm_event comes back next to mpm_prefork and Apache refuses to
# start with "AH00534: More than one MPM loaded". Enforce prefork at runtime,
# where the removal happens on the live filesystem and cannot be undone by a
# layer merge. Harmless locally.
for mpm in event worker; do
    rm -f "/etc/apache2/mods-enabled/mpm_${mpm}.load" "/etc/apache2/mods-enabled/mpm_${mpm}.conf"
done
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    a2enmod -q mpm_prefork
fi

# Many platforms inject PORT; without it Apache stays on 80.
PORT="${PORT:-80}"
sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# If a volume is ever mounted on uploads it arrives root-owned; Apache must own it.
UPLOADS=/var/www/html/wp-content/uploads
mkdir -p "$UPLOADS"
chown www-data:www-data "$UPLOADS" 2>/dev/null || true

# Browsers share localhost cookies across ports. Other development apps can
# push WordPress's Cookie header above Apache's default 8190-byte field limit.
# Relax that limit only in our loopback-only Compose stack, never in production.
if [ "${WP_ENVIRONMENT_TYPE:-production}" = "local" ] && [ "${WP_LOCAL_STACK:-0}" = "1" ]; then
    cat > /etc/apache2/conf-enabled/zz-local-request-limits.conf <<'CONF'
LimitRequestFieldSize 65536
CONF
else
    rm -f /etc/apache2/conf-enabled/zz-local-request-limits.conf
fi

# Production-only tuning. wp-config.php defaults WP_ENVIRONMENT_TYPE to production too.
if [ "${WP_ENVIRONMENT_TYPE:-production}" = "production" ]; then
    # Code never changes inside a running production container, so skip the
    # per-request stat() calls. Locally the bind mount needs them.
    cat > /usr/local/etc/php/conf.d/zz-production.ini <<'INI'
opcache.validate_timestamps = 0
INI
    # HSTS only when TLS was terminated upstream (the host's proxy). Never locally:
    # HSTS on "localhost" would force HTTPS on every other local dev server in
    # the developer's browser.
    cat > /etc/apache2/conf-enabled/zz-production.conf <<'CONF'
SetEnvIf X-Forwarded-Proto "^https$" SITE_HTTPS=1
Header always set Strict-Transport-Security "max-age=31536000" env=SITE_HTTPS
CONF
fi

exec "$@"
