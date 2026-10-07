# syntax=docker/dockerfile:1
# One image for local dev (docker compose) and production on any container host.
# Apache + mod_php keeps it to a single process, which suits PaaS platforms.
# Production-only behaviour (OPcache, HSTS) is switched on by docker/entrypoint.sh
# from WP_ENVIRONMENT_TYPE, so the same image is what gets tested locally.

# Plugins listed in docker/external-plugins.txt come from public GitHub
# repositories rather than from this repo. This stage re-runs only when the list
# or the script changes.
FROM php:8.4-apache AS external-plugins
COPY docker/fetch-plugins.sh docker/external-plugins.txt /tmp/
RUN sh /tmp/fetch-plugins.sh /tmp/external-plugins.txt /plugins

FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libavif-dev \
        libzip-dev libicu-dev unzip less default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" gd mysqli exif intl zip bcmath opcache \
    && a2enmod rewrite headers expires remoteip \
    && rm -rf /var/lib/apt/lists/*

# WP-CLI, runs as root inside the container.
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp
ENV WP_CLI_ALLOW_ROOT=1

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/wordpress.ini /usr/local/etc/php/conf.d/wordpress.ini
COPY docker/apache/wordpress.conf /etc/apache2/conf-available/wordpress.conf
COPY docker/apache/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
RUN a2enconf wordpress
# Apache expands ${APACHE_MAX_REQUEST_WORKERS} in mpm_prefork.conf; override per environment.
ENV APACHE_MAX_REQUEST_WORKERS=10
COPY docker/entrypoint.sh /usr/local/bin/wp-entrypoint
RUN chmod +x /usr/local/bin/wp-entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=external-plugins /plugins/ /var/www/html/wp-content/plugins/

ENTRYPOINT ["wp-entrypoint"]
CMD ["apache2-foreground"]
