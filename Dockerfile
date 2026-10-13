FROM php:8.5-cli

# intl is the one extension the search engine itself needs: Normalizer
# (Unicode NFKC) runs on every document and every query. mbstring is already
# compiled into the official image; pcntl carries SIGTERM for graceful
# shutdown.
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        procps \
        htop \
        libicu-dev \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pcntl posix intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV PHP_IDE_CONFIG serverName=php-mini-search

# start_with_request=trigger, not yes: the integration tests start the server
# as a child process, and with "yes" every one of those processes tries to
# reach a debugger that usually isn't listening. Debugging is opt-in per run:
#   XDEBUG_TRIGGER=1 bin/mini-search serve      (or the IDE's own trigger)
RUN { \
        echo 'zend_extension=xdebug'; \
        echo 'xdebug.mode=debug'; \
        echo 'xdebug.start_with_request=trigger'; \
        echo 'xdebug.client_host=host.docker.internal'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.discover_client_host=1'; \
    } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN mkdir -p /root/.config/htop && { \
        echo 'fields=0 48 2 46 47 49 1'; \
        echo 'sort_key=48'; \
        echo 'tree_view=1'; \
        echo 'hide_kernel_threads=1'; \
    } > /root/.config/htop/htoprc

WORKDIR /app
