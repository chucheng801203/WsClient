FROM php:7.4-cli

COPY --from=composer:2.2.2 /usr/bin/composer /usr/local/bin/composer

# set work directory
WORKDIR /wsTest

# insatll php extensions
RUN apt-get update && apt-get install -y \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install -j$(nproc) zip sockets

# change time zone
RUN ln -sf /usr/share/zoneinfo/ROC /etc/localtime
