# Build stage — instala dependencias de Composer y genera el autoload optimizado.
# El resultado de este build nunca se ejecuta: el packer de Celaut solo exporta el
# filesystem final (ver docs/PACKING.md del nodo). CMD/ENTRYPOINT se ignoran.
FROM composer:2 AS composer

WORKDIR /app

COPY ./composer.json ./composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY ./app ./app
COPY ./bootstrap ./bootstrap
COPY ./config ./config
COPY ./database ./database
COPY ./public ./public
COPY ./resources ./resources
COPY ./routes ./routes
COPY ./artisan ./artisan

# --no-scripts: no arranca Laravel (package:discover, filament:upgrade) durante el
# build, que no tiene .env ni base de datos; se ejecuta en el primer arranque real
# (ver start.sh).
RUN composer dump-autoload --optimize --no-scripts

# Runtime stage — únicamente esta capa se exporta como filesystem del servicio.
FROM php:8.4-cli

# pg_dump debe ser de la misma versión mayor que el servidor (Postgres 18) o se niega
# a hacer el backup; Debian trae la 17, así que se instala desde el repositorio PGDG.
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        postgresql-client-18 \
        python3 \
        python3-venv \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        pcntl \
        intl \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer /app ./

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY pf_entrypoint.py /usr/local/bin/pf-entrypoint

# Orquestación: celaut-service-libraries (node_controller) + bee-rpc, fijadas por commit.
# --no-deps: bee-rpc pina grpcio==1.56.0 (sin wheel para este Python); se instalan las
# dependencias reales aparte.
RUN python3 -m venv /opt/pf-venv \
    && /opt/pf-venv/bin/pip install --no-cache-dir grpcio "protobuf>=4.23.3" \
    && /opt/pf-venv/bin/pip install --no-cache-dir --no-deps \
        https://github.com/bee-rpc-protocol/bee-rpc-over-grpc-py/archive/8049fd86261c0d85445e6c27d363e0c82d5b2958.tar.gz \
        https://github.com/bee-rpc-protocol/celaut-service-libraries/archive/23459eea47351c75cffffa9318049aad8810ea75.tar.gz \
    && /opt/pf-venv/bin/python -c "from bee_rpc import client; from node_controller.controller.controller import Controller; print('node_controller ok')"

# Dependencias Celaut empaquetadas por el packer (pf-postgres) y sus hashes.
COPY ./__services__ /app/__services__
COPY ./__metadata__ /app/__metadata__
COPY ./__block__ /app/__block__
COPY ./.dependencies /app/.dependencies

RUN chmod +x /usr/local/bin/pf-entrypoint \
    && mkdir -p \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
