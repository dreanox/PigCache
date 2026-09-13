# WordPress image used by the test stack.
#
# The version is a build arg so the suite can be pointed at any release without
# editing this file. CI runs it against both the newest stable WordPress and the
# oldest one readme.txt claims support for, because drop-ins hook into internals
# that core does occasionally change.
#
#   WP_VERSION=6.9 bash tests/run.sh integration
ARG WP_VERSION=7.1
ARG PHP_VERSION=8.2

FROM wordpress:${WP_VERSION}-php${PHP_VERSION}-apache

RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear
