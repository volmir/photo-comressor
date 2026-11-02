FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd exif \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY docker/php.ini /usr/local/etc/php/conf.d/photos.ini
COPY run.php ./
COPY tests ./tests
RUN mkdir input output && chown -R 1000:1000 /app

ENV PHP_MEMORY_LIMIT=512M
USER 1000:1000
ENTRYPOINT ["php", "/app/run.php"]
