# Relay / lobby / WebRTC signalling server (plain PHP, no extension needed)
FROM php:8.3-cli-alpine
RUN docker-php-ext-install pcntl
WORKDIR /app
COPY php/src /app/php/src
COPY server/bin /app/server/bin
ENV PHP_WASM_NETWORK_LISTEN=tcp://0.0.0.0:8090
EXPOSE 8090
USER nobody
CMD ["php", "-d", "memory_limit=256M", "/app/server/bin/server.php"]
