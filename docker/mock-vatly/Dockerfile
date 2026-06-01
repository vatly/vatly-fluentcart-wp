FROM php:8.2-cli-alpine

WORKDIR /srv
COPY server.php /srv/server.php
COPY router.php /srv/router.php

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "router.php"]
