<?php

namespace PhpWasmNetwork;

/**
 * A connection to the relay server and to the peers of a room. Never blocks: the application calls poll()
 * once per frame and reacts to the events.
 *
 * Implementations: WasmConnection (PHP in the browser: the wasmnet extension and js/php-wasm-network.js,
 * WebSocket or WebRTC) and NativeConnection (PHP CLI / desktop: WebSocket through the relay).
 */
interface Connection
{
    public const CONNECTING = 0;
    public const OPEN = 1;
    public const CLOSING = 2;
    public const CLOSED = 3;

    /** Creates a room (Event::ROOM with its code once done). $meta: anything the others see when joining. */
    public function createRoom(int $maxPeers = 8, array $meta = []): void;

    /** Joins the room $code (Event::ROOM, or Event::ERROR no-room / room-full). */
    public function joinRoom(string $code): void;

    public function leaveRoom(): void;

    /**
     * Sends $data to the peer $to (null: every other peer of the room).
     * $reliable false: may be lost or arrive out of order, never late (WebRTC; the relay is always reliable).
     */
    public function send(string $data, ?int $to = null, bool $reliable = true): bool;

    /** Asks the server for a round trip time (Event::PONG with ms). */
    public function ping(): void;

    /** @return Event[] what happened since the last call */
    public function poll(): array;

    public function state(): int;

    public function close(): void;
}
