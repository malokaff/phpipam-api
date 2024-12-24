FROM php:8.2-apache
RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN apt-get update
RUN apt-get install -y unzip
WORKDIR /var/www/html/EW-demo-frontend
RUN curl -sS https://getcomposer.org/installer | php
RUN php composer.phar require php-mqtt/client