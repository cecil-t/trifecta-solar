# Trifecta Solar Tracker - runtime image
# PHP 8.3 + Apache. SQLite (pdo_sqlite) and Argon2 password hashing are built into the official image.
FROM php:8.3-apache

ENV TZ=America/New_York

RUN a2enmod headers \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "ServerTokens Prod\nServerSignature Off" > /etc/apache2/conf-enabled/zz-security.conf \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-trifecta.ini"
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/trifecta-entrypoint
RUN chmod +x /usr/local/bin/trifecta-entrypoint

WORKDIR /var/www/app
# The code is baked into the image, but docker-compose.yml also bind-mounts the
# repo over this path so a `git pull` on the host goes live without a rebuild.
COPY . /var/www/app

ENTRYPOINT ["trifecta-entrypoint"]
CMD ["apache2-foreground"]
