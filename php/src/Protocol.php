<?php

namespace PhpWasmNetwork;

/**
 * The protocol between the clients and the relay server (php/src/Server/RelayServer.php), over one WebSocket:
 *
 * - text frames: JSON control messages, {"t": type, ...}
 *     client -> server: create {max, meta, public, watch}, join {room}, watch {room}, quick {max, meta}, lock {locked},
 *                       info {data}, leave, signal {to, data}, ping {id}
 *     server -> client: hello {v}, room {room, peer, host, peers, watchers, watcher, meta, max, public}, peer-join {peer},
 *                       watcher-join {peer}, peer-leave {peer} (a peer or a watcher), host {peer} (-1: no peer left),
 *                       signal {from, data}, pong {id}, error {code, message}
 * - binary frames: messages relayed by the server inside the room
 *     client -> server: [to: uint8 (BROADCAST: every other peer; WATCHERS: every watcher)] payload
 *     server -> client: [from: uint8] payload
 *
 * Watchers (watch, or create with watch: true): a web page that follows a room (who is in it, its chat) without
 * playing. Ids from FIRST_WATCHER, never the host, not counted in max. A peer's BROADCAST doesn't reach them (it
 * may go peer to peer over WebRTC anyway): what they need is sent to WATCHERS. A watcher's BROADCAST reaches the
 * peers and the other watchers. A room is kept a moment once empty (the page opens the game, which joins it).
 *
 * info {data}: what the list of rooms shows (players, match...), set by the host, or by a watcher while no peer
 * is in the room. Plain HTTP GET /health?rooms: the counts and the public rooms with their info.
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
    /** Binary frames: to every watcher of the room. */
    public const WATCHERS = 254;
    /** Watcher ids: from this one (peers: 0 to MAX_PEERS - 1). */
    public const FIRST_WATCHER = 128;
    public const MAX_PEERS = 16;
    public const MAX_MESSAGE = 65536;
    /** Room codes: 4 letters, no I / O (read on a TV, typed on a phone). */
    public const ROOM_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
}
