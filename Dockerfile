FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# The app's own .htaccess raises upload limits and blocks direct access to
# data/ and cache/ - both require AllowOverride, which the base image disables.
RUN printf '<Directory /var/www/html/>\n    AllowOverride All\n</Directory>\n' \
    > /etc/apache2/conf-available/marksheet-allow-override.conf \
    && a2enconf marksheet-allow-override

COPY . /var/www/html/

RUN mkdir -p /var/www/html/data /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html/data /var/www/html/cache

EXPOSE 80

# Railway assigns the listen port at runtime via $PORT; Apache's default
# config only knows about 80, so both files are patched at container start.
CMD ["sh", "-c", "sed -i \"s/80/${PORT:-80}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
