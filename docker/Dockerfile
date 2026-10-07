# One image, five process roles (web, realtime, scheduler, worker-connector,
# worker-compute). The role is chosen by the container command; all settings
# come from environment variables.

# --- base: PHP 8.5 with every extension the platform needs ---
FROM php:8.5-fpm-bookworm AS base
COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath intl zip pdo_pgsql redis uv opentelemetry pcntl opcache

# --- build: Composer dependencies and Vite assets (Node 22) ---
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && apt-get update -qq && apt-get install -y -qq --no-install-recommends git unzip >/dev/null \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
 && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build

# --- runtime: php-fpm + nginx as a non-root user on unprivileged ports ---
FROM base AS runtime
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends nginx \
 && rm -rf /var/lib/apt/lists/* \
 && sed -i '/^user = /d;/^group = /d' /usr/local/etc/php-fpm.d/www.conf \
 && useradd --uid 10001 --create-home --shell /usr/sbin/nologin app \
 && install -d -o app -g app /tmp/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-dashflow.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-dashflow.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
WORKDIR /var/www/html
COPY --from=build --chown=app:app /app /var/www/html
RUN rm -rf docker node_modules resources/js tests \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R app:app storage bootstrap/cache \
 && chmod +x /usr/local/bin/entrypoint.sh
USER app
EXPOSE 8080 8081
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
