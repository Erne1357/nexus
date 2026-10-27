# syntax=docker/dockerfile:1.7

# Imágenes base fijadas por versión exacta + digest (builds reproducibles).
# Para actualizar: cambiar tag y digest juntos (`docker buildx imagetools inspect <img>:<tag>`).
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.4-php8.5-trixie@sha256:ada77754f475352a9105b7961f89ab17ece97048514799532d2d27a1dea2fda7
ARG COMPOSER_IMAGE=composer:2.9.8@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d
ARG NODE_IMAGE=node:24.22.1-alpine3.24@sha256:06d7adf3e0ef61a13f1d28682726366f97a15aa1888c934d3ecec86c33918b53

FROM ${COMPOSER_IMAGE} AS composer

# ---------------------------------------------------------------------------
# base: runtime PHP + extensiones + usuario sin privilegios
# ---------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS base

ARG UID=1000
ARG GID=1000

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip \
    && groupadd --gid ${GID} app \
    && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash app \
    && chown -R app:app /config /data

COPY --link --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

ENV COMPOSER_HOME=/tmp/composer \
    OCTANE_SERVER=frankenphp

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8000/up || exit 1

# ---------------------------------------------------------------------------
# dev: código montado como volumen, Octane con recarga automática
# ---------------------------------------------------------------------------
FROM base AS dev

# sockets: lo exige pestphp/pest-plugin-browser (solo dev y CI, no prod)
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions sockets

USER app

CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2", "--max-requests=250", "--watch"]

# ---------------------------------------------------------------------------
# vendor: dependencias PHP de producción (capa cacheada por composer.lock)
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---------------------------------------------------------------------------
# assets: build de Vite (capa cacheada por package-lock.json)
# ---------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app

COPY package.json package-lock.json ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
# Tailwind escanea las vistas de paginación de Laravel y los componentes de MaryUI
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
COPY --from=vendor /app/vendor/robsontenorio/mary/src/View/Components ./vendor/robsontenorio/mary/src/View/Components

RUN npm run build

# ---------------------------------------------------------------------------
# prod: imagen inmutable con código, vendor y assets compilados
# ---------------------------------------------------------------------------
FROM base AS prod

ARG UID=1000
ARG GID=1000

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY docker/entrypoint.prod.sh /usr/local/bin/entrypoint

COPY --link --chown=${UID}:${GID} --from=vendor /app/vendor ./vendor
COPY --link --chown=${UID}:${GID} . .
COPY --link --chown=${UID}:${GID} --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi \
    && chown -R app:app storage bootstrap/cache

USER app

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--max-requests=1000"]
