FROM trueasync/php-true-async:latest AS base

COPY --from=composer:latest /usr/bin/composer /usr/bin

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app

CMD ["php", "bin/mezzio-async", "start"]
