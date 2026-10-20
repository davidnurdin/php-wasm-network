<?php

namespace PhpWasmNetwork;

/**
 * The protocol between the clients and the relay server (php/src/Server/RelayServer.php), over one WebSocket:
 *
 * - text frames: JSON control messages, {"t": type, ...}
 *     client -> server: create {max, meta, public}, join {room}, quick {max, meta}, lock {locked}, leave,
 *                       signal {to, data}, ping {id}
 *     server -> client: hello {v}, room {room, peer, host, peers, meta, max, public}, peer-join {peer}, peer-leave {peer},
 *                       host {peer}, signal {from, data}, pong {id}, error {code, message}
 * - binary frames: messages relayed by the server inside the room
 *     client -> server: [to: uint8 (BROADCAST: everybody else)] payload
 *     server -> client: [from: uint8] payload
 *
 * Public rooms (create with public: true, or quick): "quick" joins the public room with the same meta that has the
 * most players and a free place, unless its host locked it (a match is being played), or creates one.
 *
 * WebRTC (browser only): the peers of a room exchange their offers / answers / ICE candidates through
 * "signal", then talk directly over two DataChannels (reliable + ordered, unreliable + unordered). The relay
 * stays the fallback for a peer that can't be reached directly.
 */
final class Protocol
{
    public const VERSION = 1;
    public const BROADCAST = 255;
    public const MAX_PEERS = 16;
    public const MAX_MESSAGE = 65536;
    /** Room codes: 4 letters, no I / O (read on a TV, typed on a phone). */
    public const ROOM_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
}
