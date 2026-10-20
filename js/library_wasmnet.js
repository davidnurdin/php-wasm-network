// emscripten --js-library for ext/wasmnet: the C functions wasmnet_js_* call window.PhpWasmNetwork
// (js/php-wasm-network.js, loaded by the page before the engine). Kept thin on purpose: the network code can
// change without rebuilding the engine.
mergeInto(LibraryManager.library, {
	wasmnet_js_connect__deps: ['$UTF8ToString'],
	wasmnet_js_connect: function (url, options) {
		var N = globalThis.PhpWasmNetwork;
		if (!N) {
			console.warn('[wasmnet] php-wasm-network.js is not loaded');
			return -1;
		}
		return N.connect(UTF8ToString(url), UTF8ToString(options));
	},
	wasmnet_js_control__deps: ['$UTF8ToString'],
	wasmnet_js_control: function (handle, json) {
		var N = globalThis.PhpWasmNetwork;
		return N && N.control(handle, UTF8ToString(json)) ? 1 : 0;
	},
	wasmnet_js_send: function (handle, data, length, to, reliable) {
		var N = globalThis.PhpWasmNetwork;
		return N && N.send(handle, HEAPU8.slice(data, data + length), to, !!reliable) ? 1 : 0;
	},
	wasmnet_js_peek: function (handle, meta) {
		var N = globalThis.PhpWasmNetwork;
		var event = N && N.peek(handle);
		if (!event) return -1;
		HEAP32[meta >> 2] = event.type;
		HEAP32[(meta >> 2) + 1] = event.from;
		return event.data.length;
	},
	wasmnet_js_take: function (handle, buffer) {
		var event = globalThis.PhpWasmNetwork.shift(handle);
		if (event && event.data.length) HEAPU8.set(event.data, buffer);
	},
	wasmnet_js_state: function (handle) {
		var N = globalThis.PhpWasmNetwork;
		return N ? N.state(handle) : 3;
	},
	wasmnet_js_close: function (handle) {
		var N = globalThis.PhpWasmNetwork;
		if (N) N.close(handle);
	},
});
