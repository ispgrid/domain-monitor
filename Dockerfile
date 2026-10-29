# Domain Monitor Docker image: Apache + PHP with the app and its Composer packages built in.
# Everything that has to survive an upgrade lives in the /data volume (see docker/entrypoint.sh).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY app/ app/
COPY core/ core/
RUN composer dump-autoload --no-dev --optimize


FROM php:8.4-apache

LABEL org.opencontainers.image.title="Domain Monitor" \
      org.opencontainers.image.description="Self-hosted domain, SSL and DNS monitoring" \
      org.opencontainers.image.source="https://github.com/ispgrid/domain-monitor" \
      org.opencontainers.image.licenses="MIT"

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl zip gd bcmath \
    && a2enmod rewrite headers remoteip \
    && mkdir -p /etc/apache2/domain-monitor

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-domain-monitor.ini

# Runtime files point into /data; the shipped uploads folder is kept as the default to copy from.
# The base image leaves /var/www/html world-writable and sticky, where the kernel won't let
# www-data follow root's symlinks, so the app folder is made root-owned and read-only.
RUN set -eux; \
    chown root:root /var/www/html; \
    chmod 755 /var/www/html; \
    mkdir -p /usr/src/domain-monitor; \
    mv public/assets/uploads /usr/src/domain-monitor/uploads; \
    rm -rf logs cache .env .installed; \
    ln -s /data/.env .env; \
    ln -s /data/.installed .installed; \
    ln -s /data/logs logs; \
    ln -s /data/cache cache; \
    ln -s /data/uploads public/assets/uploads; \
    chmod +x docker/entrypoint.sh

ENV DOCKER=true
VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=1m --timeout=10s --start-period=1m \
    CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
