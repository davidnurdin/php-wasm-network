dnl wasmnet: PHP <-> network bridge for php-wasm (the JS side is js/php-wasm-network.js, linked with
dnl --js-library js/library_wasmnet.js)
PHP_ARG_ENABLE([wasmnet],
  [whether to enable wasmnet (php-wasm network bridge)],
  [AS_HELP_STRING([--enable-wasmnet], [Enable the php-wasm network bridge])],
  [no])

if test "$PHP_WASMNET" != "no"; then
  PHP_NEW_EXTENSION(wasmnet, wasmnet.c, $ext_shared)
fi
