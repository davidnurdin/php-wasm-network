<?php
// Relay / lobby / WebRTC signalling server: php server/bin/server.php [address] (default tcp://0.0.0.0:8090,
// or $PHP_WASM_NETWORK_LISTEN). Put a TLS reverse proxy in front of it for wss:// (see server/README.md).

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(function (string $class) {
        $prefix = 'PhpWasmNetwork\\';
        if (str_starts_with($class, $prefix)) {
            require __DIR__ . '/../../php/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
}

$address = $argv[1] ?? getenv('PHP_WASM_NETWORK_LISTEN') ?: 'tcp://0.0.0.0:8090';
$server = new PhpWasmNetwork\Server\RelayServer($address, fn(string $line) => fwrite(STDERR, $line . "\n"));
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn() => $server->stop());
    pcntl_signal(SIGINT, fn() => $server->stop());
}
$server->run();
