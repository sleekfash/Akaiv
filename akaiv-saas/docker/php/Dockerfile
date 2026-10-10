FROM php:8.3-fpm-alpine

# ------------------------------
# System dependencies
# ------------------------------
RUN apk add --no-cache \
    git \
    curl \
    wget \
    zip \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libpq-dev \
    sqlite-dev \
    libxml2-dev \
    oniguruma-dev \
    icu-dev \
    imagemagick-dev \
    pcre-dev \
    autoconf \
    g++ \
    make \
    linux-headers \
    tesseract-ocr \
    tesseract-ocr-data-eng \
    libreoffice \
    poppler-utils

# ------------------------------
# PHP extensions
# ------------------------------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        calendar \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pdo_mysql \
        pdo_sqlite \
        pgsql \
        soap \
        zip \
        sockets

# ------------------------------
# PECL extensions
# ------------------------------
RUN pecl install redis imagick \
    && docker-php-ext-enable redis imagick

# ------------------------------
# Composer
# ------------------------------
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ------------------------------
# Node.js + npm (for Vite builds)
# ------------------------------
COPY --from=node:20-alpine /usr/lib /usr/lib
COPY --from=node:20-alpine /usr/bin /usr/bin
COPY --from=node:20-alpine /opt /opt
COPY --from=node:20-alpine /root /root
ENV PATH="/root/.npm-global/bin:$PATH"

# ------------------------------
# Application user
# ------------------------------
RUN addgroup -g 1000 akaiv && adduser -u 1000 -G akaiv -s /bin/sh -D akaiv

WORKDIR /var/www

USER akaiv

EXPOSE 9000

CMD ["php-fpm"]
