FROM composer:latest AS composer

FROM trueasync/php-true-async:latest
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
CMD ["php", "-S", "0.0.0.0:80", "-t", "public", "public/index.php"]
EXPOSE 80