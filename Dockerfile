# common stage
FROM php:7.2-cli-alpine AS common

RUN apk add bash git zlib-dev libzip-dev
RUN docker-php-ext-install zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
&& chmod +x /usr/bin/composer

WORKDIR /
COPY . /home

# test stage
FROM scratch AS test
COPY --from=common / /

ENTRYPOINT /home/ci/test.sh

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
