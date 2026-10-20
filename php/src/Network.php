<?php

namespace PhpWasmNetwork;

/**
 * Entry point: the right Connection for where PHP runs.
 *
 *   $net = Network::connect('wss://example.org/ws', ['transport' => 'webrtc']);
 *   $net->createRoom(4, ['mode' => 'race']);        // or $net->joinRoom('ABCD')
 *   // every frame:
 *   foreach ($net->poll() as $event) { ... }
 *   $net->send($bytes);                              // to everybody else in the room
 */
final class Network
{
    /**
     * @param array $options transport: 'ws' (default) or 'webrtc' (browser only; native PHP always uses the relay);
     *                       iceServers: RTCIceServer[] for WebRTC (default: a public STUN server)
     */
    public static function connect(string $url, array $options = []): Connection
    {
        if (WasmConnection::isAvailable()) return new WasmConnection($url, $options);
        return new NativeConnection($url, (float)($options['timeout'] ?? 5.0));
    }

    /** The browser engine has the wasmnet extension (and so WebRTC). */
    public static function isWasm(): bool
    {
        return WasmConnection::isAvailable();
    }
}
