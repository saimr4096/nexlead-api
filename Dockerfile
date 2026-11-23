
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

COPY api.php config.php /var/www/html/

EXPOSE 80
