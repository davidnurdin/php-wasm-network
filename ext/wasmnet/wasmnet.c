/*
 * wasmnet: network connections for PHP compiled to WebAssembly (php-wasm).
 *
 * The functions only pass the calls to JavaScript (js/library_wasmnet.js -> window.PhpWasmNetwork, from
 * js/php-wasm-network.js), which holds the WebSockets and the WebRTC peers. Nothing blocks: PHP polls the
 * queued events once per frame. The PHP API on top of it is PhpWasmNetwork\WasmConnection.
 *
 *   wasmnet_connect(string $url, string $options = '{}'): int|false   handle; false: php-wasm-network.js missing
 *   wasmnet_control(int $handle, string $json): bool                  create / join / leave / ping (Protocol.php)
 *   wasmnet_send(int $handle, string $data, int $to = 255, bool $reliable = true): bool
 *   wasmnet_poll(int $handle): ?array                                 [type, from, data] or null (Event.php)
 *   wasmnet_state(int $handle): int                                   0 connecting, 1 open, 2 closing, 3 closed
 *   wasmnet_close(int $handle): void
 *
 * Outside emscripten the extension builds but has no transport: wasmnet_connect() returns false.
 */
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "php_wasmnet.h"

#ifdef __EMSCRIPTEN__
extern int wasmnet_js_connect(const char *url, const char *options);
extern int wasmnet_js_control(int handle, const char *json);
extern int wasmnet_js_send(int handle, const char *data, int length, int to, int reliable);
extern int wasmnet_js_peek(int handle, int *meta);
extern void wasmnet_js_take(int handle, char *buffer);
extern int wasmnet_js_state(int handle);
extern void wasmnet_js_close(int handle);
#else
static int wasmnet_js_connect(const char *url, const char *options) { return -1; }
static int wasmnet_js_control(int handle, const char *json) { return 0; }
static int wasmnet_js_send(int handle, const char *data, int length, int to, int reliable) { return 0; }
static int wasmnet_js_peek(int handle, int *meta) { return -1; }
static void wasmnet_js_take(int handle, char *buffer) { }
static int wasmnet_js_state(int handle) { return 3; }
static void wasmnet_js_close(int handle) { }
#endif

PHP_FUNCTION(wasmnet_connect)
{
	char *url, *options = "{}";
	size_t url_len, options_len = 2;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STRING(url, url_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING(options, options_len)
	ZEND_PARSE_PARAMETERS_END();

	int handle = wasmnet_js_connect(url, options);
	if (handle < 0) {
		RETURN_FALSE;
	}
	RETURN_LONG(handle);
}

PHP_FUNCTION(wasmnet_control)
{
	zend_long handle;
	char *json;
	size_t json_len;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(handle)
		Z_PARAM_STRING(json, json_len)
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL(wasmnet_js_control((int)handle, json));
}

PHP_FUNCTION(wasmnet_send)
{
	zend_long handle, to = 255;
	char *data;
	size_t data_len;
	bool reliable = 1;

	ZEND_PARSE_PARAMETERS_START(2, 4)
		Z_PARAM_LONG(handle)
		Z_PARAM_STRING(data, data_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(to)
		Z_PARAM_BOOL(reliable)
	ZEND_PARSE_PARAMETERS_END();

	RETURN_BOOL(wasmnet_js_send((int)handle, data, (int)data_len, (int)to, reliable ? 1 : 0));
}

PHP_FUNCTION(wasmnet_poll)
{
	zend_long handle;
	int meta[2] = {0, -1};

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(handle)
	ZEND_PARSE_PARAMETERS_END();

	int length = wasmnet_js_peek((int)handle, meta);
	if (length < 0) {
		RETURN_NULL();
	}
	zend_string *data = zend_string_alloc(length, 0);
	wasmnet_js_take((int)handle, ZSTR_VAL(data));
	ZSTR_VAL(data)[length] = '\0';

	array_init_size(return_value, 3);
	add_next_index_long(return_value, meta[0]);
	add_next_index_long(return_value, meta[1]);
	add_next_index_str(return_value, data);
}

PHP_FUNCTION(wasmnet_state)
{
	zend_long handle;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(handle)
	ZEND_PARSE_PARAMETERS_END();

	RETURN_LONG(wasmnet_js_state((int)handle));
}

PHP_FUNCTION(wasmnet_close)
{
	zend_long handle;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(handle)
	ZEND_PARSE_PARAMETERS_END();

	wasmnet_js_close((int)handle);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmnet_connect, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, options, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmnet_control, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, json, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmnet_send, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, to, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, reliable, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_wasmnet_handle, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry wasmnet_functions[] = {
	PHP_FE(wasmnet_connect, arginfo_wasmnet_connect)
	PHP_FE(wasmnet_control, arginfo_wasmnet_control)
	PHP_FE(wasmnet_send, arginfo_wasmnet_send)
	PHP_FE(wasmnet_poll, arginfo_wasmnet_handle)
	PHP_FE(wasmnet_state, arginfo_wasmnet_handle)
	PHP_FE(wasmnet_close, arginfo_wasmnet_handle)
	PHP_FE_END
};

PHP_MINFO_FUNCTION(wasmnet)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "wasmnet (php-wasm network bridge)", "enabled");
	php_info_print_table_row(2, "Version", PHP_WASMNET_VERSION);
	php_info_print_table_end();
}

zend_module_entry wasmnet_module_entry = {
	STANDARD_MODULE_HEADER,
	"wasmnet",
	wasmnet_functions,
	NULL,
	NULL,
	NULL,
	NULL,
	PHP_MINFO(wasmnet),
	PHP_WASMNET_VERSION,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_WASMNET
ZEND_GET_MODULE(wasmnet)
#endif
