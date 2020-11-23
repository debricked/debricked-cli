# common stage
FROM php:7.4-cli-alpine AS common

RUN apk add bash git zlib-dev libzip-dev
RUN docker-php-ext-install zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
&& chmod +x /usr/bin/composer

WORKDIR /
COPY . /home

# dev stage
FROM php:7.4-cli-alpine AS dev
COPY --from=common / /

RUN apk add --no-cache $PHPIZE_DEPS && pecl install xdebug && docker-php-ext-enable xdebug

# blackfire
ENV BLACKFIRE_CONFIG /dev/null
ENV BLACKFIRE_LOG_LEVEL 4
RUN curl https://github.com/blackfireio/docker/raw/master/blackfire --output /usr/bin/blackfire -L \
    && chmod +x /usr/bin/blackfire
RUN curl https://github.com/blackfireio/docker/raw/master/blackfire-agent --output /usr/bin/blackfire-agent -L \
    && chmod +x /usr/bin/blackfire-agent
RUN mkdir -p /var/run/blackfire /var/log/blackfire
RUN chown 1001:1001 /var/run/blackfire /var/log/blackfire

ENV current_os=alpine
RUN version=$(php -r "echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;") \
    && curl -A "Docker" -o /tmp/blackfire-probe.tar.gz -D - -L -s https://blackfire.io/api/v1/releases/probe/php/$current_os/amd64/$version \
    && mkdir -p /tmp/blackfire \
    && tar zxpf /tmp/blackfire-probe.tar.gz -C /tmp/blackfire \
    && mv /tmp/blackfire/blackfire-*.so $(php -r "echo ini_get('extension_dir');")/blackfire.so \
    && printf "extension=blackfire.so\nblackfire.agent_socket=unix:///var/run/blackfire/agent.sock\n" > $PHP_INI_DIR/conf.d/blackfire.ini \
    && rm -rf /tmp/blackfire /tmp/blackfire-probe.tar.gz


RUN rm -Rf /home/vendor && /home/bin/console about --env=test
WORKDIR /home
#RUN composer install # no need to install since we'll mount host dir to /home during dev anyway.

USER 1001:1001
CMD bash

# test stage
FROM scratch AS test
COPY --from=common / /

CMD /home/ci/test.sh

# cli stage
FROM scratch AS cli
COPY --from=common / /

# Create suitable point where we expect dependency files to be mounted.
RUN mkdir /data

# Run script once to install deps once and for all.
RUN /home/entrypoint.sh

ENTRYPOINT ["/home/entrypoint.sh"]

# Default is same behaviour as if no arguments are given.
CMD []
