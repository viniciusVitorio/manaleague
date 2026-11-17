# syntax=docker/dockerfile:1
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache libzip-dev sqlite-dev libpq-dev icu-dev oniguruma-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql pdo_sqlite intl mbstring opcache gd exif pcntl
WORKDIR /var/www/html
# Deliberate allowlist: no host vendor, storage, .env, .git or production deployment config.
COPY --from=vendor /app/vendor ./vendor
COPY app ./app
COPY bootstrap/app.php bootstrap/providers.php ./bootstrap/
COPY config ./config
COPY database/migrations ./database/migrations
COPY database/seeders ./database/seeders
COPY resources ./resources
COPY routes ./routes
COPY public ./public
COPY artisan composer.json composer.lock ./
COPY infra/eks-lab/runtime ./infra/eks-lab/runtime
COPY infra/eks-lab/local/test-health.sh infra/eks-lab/local/test-runtime.sh infra/eks-lab/local/test-rds-guards.sh ./infra/eks-lab/local/
RUN cp public/index.php public/lab-original.php \
    && cp infra/eks-lab/runtime/index.php public/index.php \
    && mkdir -p bootstrap/cache storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod +x infra/eks-lab/runtime/entrypoint.sh \
    && chmod 0644 infra/eks-lab/runtime/certs/global-bundle.pem \
    && cd infra/eks-lab/runtime/certs && sha256sum -c global-bundle.pem.sha256
COPY infra/eks-lab/docker/php.ini /usr/local/etc/php/conf.d/99-lab.ini
COPY infra/eks-lab/docker/fpm.conf /usr/local/etc/php-fpm.d/zz-lab.conf
USER www-data
EXPOSE 9000
STOPSIGNAL SIGQUIT
ENTRYPOINT ["/var/www/html/infra/eks-lab/runtime/entrypoint.sh"]
CMD ["php-fpm", "-F"]

FROM owasp/modsecurity-crs:4.25.2-nginx-alpine-202610180210-lts AS web
# Patch fixable HIGH/CRITICAL base OS packages; retain the upstream nonroot runtime.
USER root
RUN apk upgrade --no-cache brotli-libs curl libcurl libexpat libx11 libxslt musl musl-utils perl
USER nginx
COPY public /var/www/html/public
COPY infra/eks-lab/docker/nginx.conf.template /etc/nginx/templates/conf.d/default.conf.template
COPY deploy/modsecurity/nginx/logging.conf.template /etc/nginx/templates/conf.d/logging.conf.template
COPY deploy/modsecurity/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf /etc/modsecurity.d/owasp-crs/rules/REQUEST-900-EXCLUSION-RULES-BEFORE-CRS.conf
COPY deploy/modsecurity/RESPONSE-999-EXCLUSION-RULES-AFTER-CRS.conf /etc/modsecurity.d/owasp-crs/rules/RESPONSE-999-EXCLUSION-RULES-AFTER-CRS.conf
COPY deploy/modsecurity/blocked.html /etc/nginx/waf/blocked.html
COPY infra/eks-lab/docker/RESPONSE-999-LAB-COOKIE.conf /etc/modsecurity.d/owasp-crs/rules/RESPONSE-999-LAB-COOKIE.conf
ENV PORT=8080 APP_FASTCGI=127.0.0.1:9000 LAB_VPC_CIDR=10.77.0.0/16 MODSEC_RULE_ENGINE=On BLOCKING_PARANOIA=1 ANOMALY_INBOUND=5 ANOMALY_OUTBOUND=4 \
    ALLOWED_METHODS="GET HEAD POST OPTIONS PUT PATCH DELETE" MODSEC_REQ_BODY_LIMIT=8388608 MODSEC_REQ_BODY_NOFILES_LIMIT=262144 \
    MODSEC_RESP_BODY_ACCESS=off MODSEC_AUDIT_LOG_PARTS=AHZ MODSEC_AUDIT_LOG_RELEVANT_STATUS=^403$ NGINX_WORKER_PROCESSES=1
EXPOSE 8080
