# ---- PHP + Apache ----
FROM php:8.3-apache

RUN a2enmod rewrite
RUN docker-php-ext-install pdo pdo_mysql

# ---- Install Composer ----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- Copy app ----
WORKDIR /var/www/html
COPY . .

# ---- Install PHP deps (creates vendor/) ----
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
