FROM php:8-cli-alpine

WORKDIR /var/runtime

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.* /var/runtime/
RUN composer install --no-dev -o

COPY . /var/runtime/

ENTRYPOINT [ "/var/runtime/bootstrap" ]

CMD [ "index" ]
