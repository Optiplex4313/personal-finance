FROM php:8.4-cli

# pg_dump debe ser de la misma versión mayor que el servidor (Postgres 18) o se niega
# a hacer el backup; Debian trae la 17, así que se instala desde el repositorio PGDG.
RUN install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    && . /etc/os-release \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        postgresql-client-18 \
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

CMD ["php", "-v"]
