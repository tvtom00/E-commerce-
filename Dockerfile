FROM php:8.2-apache
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y libpng-dev libzip-dev zip unzip
RUN docker-php-ext-install pdo_mysql gd zip
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --optimize-autoloader
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
EXPOSE 80
