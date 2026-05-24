FROM wordpress:6.5-php8.2-apache
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear
