<?php

namespace PhpWasmNetwork;

/**
 * Connection for PHP in the browser: the functions of the wasmnet extension (ext/wasmnet, compiled into the
 * php-wasm engine) call js/php-wasm-network.js, which holds the WebSocket and the WebRTC peers.
 *
 * Transport (option 'transport'): 'ws' (everything through the relay) or 'webrtc' (direct DataChannels
 * between browsers, signalled through the relay, which stays the fallback for a peer it can't reach).
 */
final class WasmConnection implements Connection
{
    private int $handle;
    private int $pingId = 0;

    /** @param array $options transport ('ws' | 'webrtc'), iceServers (RTCIceServer[]) */
    public function __construct(string $url, array $options = [])
    {
        $handle = \wasmnet_connect($url, json_encode($options ?: new \stdClass()));
        if ($handle === false) {
            throw new \RuntimeException('php-wasm-network.js is not loaded in the page');
        }
        $this->handle = $handle;
    }

    public static function isAvailable(): bool
    {
        return function_exists('wasmnet_connect');
    }

    public function createRoom(int $maxPeers = 8, array $meta = [], bool $public = false): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'create', 'max' => $maxPeers, 'meta' => $meta ?: new \stdClass(), 'public' => $public]));
    }

    public function quickJoin(int $maxPeers = 8, array $meta = []): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'quick', 'max' => $maxPeers, 'meta' => $meta ?: new \stdClass()]));
    }

    public function lockRoom(bool $locked): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'lock', 'locked' => $locked]));
    }

    public function joinRoom(string $code): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'join', 'room' => $code]));
    }

    public function leaveRoom(): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'leave']));
    }

    public function setRoomInfo(array $info): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'info', 'data' => $info ?: new \stdClass()]));
    }

    public function send(string $data, ?int $to = null, bool $reliable = true): bool
    {
        return \wasmnet_send($this->handle, $data, $to ?? Protocol::BROADCAST, $reliable);
    }

    public function ping(): void
    {
        \wasmnet_control($this->handle, json_encode(['t' => 'ping', 'id' => ++$this->pingId]));
    }

    public function poll(): array
    {
        $events = [];
        while (($event = \wasmnet_poll($this->handle)) !== null) {
            $events[] = new Event($event[0], $event[1], $event[2]);
        }
        return $events;
    }

    public function state(): int
    {
        return \wasmnet_state($this->handle);
    }

    public function close(): void
    {
        \wasmnet_close($this->handle);
    }
}
