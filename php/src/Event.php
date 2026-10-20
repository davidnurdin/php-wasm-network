<?php

namespace PhpWasmNetwork;

/**
 * Something that happened on a Connection, returned by Connection::poll().
 * The type values are shared with the browser side (js/php-wasm-network.js, ext/wasmnet).
 */
final class Event
{
    /** Connected to the server. */
    public const OPEN = 1;
    /** Connection closed ($data: reason). */
    public const CLOSE = 2;
    /** Error ($info: code, message), e.g. room-full, no-room. The connection stays open. */
    public const ERROR = 3;
    /** In a room ($info: room, peer = my id, host, peers = ids of the others, meta). */
    public const ROOM = 4;
    /** $from joined the room. */
    public const PEER_JOIN = 5;
    /** $from left the room. */
    public const PEER_LEAVE = 6;
    /** $data received from $from. */
    public const MESSAGE = 7;
    /** The way to $from changed ($data: 'webrtc' or 'relay'). */
    public const PEER_TRANSPORT = 8;
    /** $from is the new host (the host left). */
    public const HOST = 9;
    /** Round trip to the server ($info: ms). */
    public const PONG = 10;

    public const NAMES = [
        self::OPEN => 'open', self::CLOSE => 'close', self::ERROR => 'error', self::ROOM => 'room',
        self::PEER_JOIN => 'peer-join', self::PEER_LEAVE => 'peer-leave', self::MESSAGE => 'message',
        self::PEER_TRANSPORT => 'peer-transport', self::HOST => 'host', self::PONG => 'pong',
    ];

    /**
     * @param int $from peer id (-1: the server)
     * @param string $data the message (MESSAGE), a reason or a transport name; JSON for ROOM / ERROR / PONG
     */
    public function __construct(
        public readonly int $type,
        public readonly int $from = -1,
        public readonly string $data = '',
    ) {
    }

    /** ROOM / ERROR / PONG: the decoded JSON of $data. */
    public function info(): array
    {
        $info = json_decode($this->data, true);
        return is_array($info) ? $info : [];
    }

    public function name(): string
    {
        return self::NAMES[$this->type] ?? 'unknown';
    }
}
