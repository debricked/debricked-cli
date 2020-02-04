FROM php:7.2-cli-alpine

RUN apk add bash git zlib-dev libzip-dev
RUN docker-php-ext-install zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
&& chmod +x /usr/bin/composer

WORKDIR /
COPY . /home

ENTRYPOINT /home/ci/test.sh
