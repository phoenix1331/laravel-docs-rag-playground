FROM node:24-bookworm-slim AS node

FROM dunglas/frankenphp:php8.4

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git curl unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    pcntl \
    intl \
    zip \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN groupadd --gid ${GID} app \
    && useradd --uid ${UID} --gid ${GID} --create-home app \
    && chown -R app:app /data/caddy /config/caddy

RUN git config --system --add safe.directory '*'

WORKDIR /app

USER app

EXPOSE 8000 5173

CMD ["php", "artisan", "dev", "--inline"]
