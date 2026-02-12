FROM php:8.3-apache

RUN a2enmod rewrite
RUN docker-php-ext-install pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Show what files exist (debug)
RUN ls -la /var/www/html

# Fail fast if composer.json missing
RUN test -f composer.json

# Install dependencies with visible output
RUN composer --version
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader -vvv

RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
