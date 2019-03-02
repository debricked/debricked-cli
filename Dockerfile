FROM php:7.3-cli-alpine

RUN apk add bash git

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
&& chmod +x /usr/bin/composer

WORKDIR /home
COPY . /home

ENTRYPOINT ci/test.sh