# Build the website's styles and scripts.
FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY web/package*.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

# Keep Python reports and Laravel in the same container.
FROM python:3.12-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php8.2 \
        php8.2-cli php8.2-curl php8.2-gd php8.2-mbstring \
        php8.2-pgsql php8.2-xml php8.2-zip php8.2-bcmath \
        ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY reporting/requirements.txt reporting/requirements.txt
RUN python -m venv /opt/reporting \
    && /opt/reporting/bin/pip install --no-cache-dir \
        -r reporting/requirements.txt \
    && /opt/reporting/bin/pip check

COPY reporting/python/ reporting/python/
COPY web/ web/
COPY --from=assets /build/public/build/ web/public/build/
COPY deploy/ deploy/

WORKDIR /app/web
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    REPORTING_PYTHON_EXECUTABLE=/opt/reporting/bin/python \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN mkdir -p bootstrap/cache storage/app/private/reporting \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist \
        --no-interaction --optimize-autoloader \
    && cp /app/deploy/apache.conf /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/\r$//' /app/deploy/start.sh \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["sh", "/app/deploy/start.sh"]