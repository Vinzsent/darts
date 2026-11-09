FROM php:8.3-apache

# Install required system libraries
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo_mysql \
        gd \
        intl \
        zip \
        mbstring \
        curl \
        exif \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache permissions
RUN sed -i 's/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

# Copy application
COPY --chown=www-data:www-data . /var/www/html/

# Set working directory
WORKDIR /var/www/html

EXPOSE 80
