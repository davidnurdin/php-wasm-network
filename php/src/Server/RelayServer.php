<?php

namespace PhpWasmNetwork\Server;

use PhpWasmNetwork\Protocol;
use PhpWasmNetwork\WebSocket\Frames;

/**
 * Lobby, relay and WebRTC signalling server (protocol: PhpWasmNetwork\Protocol). One process, one
 * stream_select() loop, no dependency. TLS (wss://) is done in front of it by a reverse proxy (Caddy, nginx).
 *
 * Rooms: a 4-letter code, up to $max peers numbered 0, 1, 2... (the creator is 0 and the host; when the host
 * leaves, the lowest id left becomes the host). The server doesn't look into the messages it relays.
 *
 * Watchers (a web page next to the game: the room, its chat): numbered from Protocol::FIRST_WATCHER, never
 * players nor host, not counted in $max. A peer's broadcast doesn't reach them: the application sends them
 * what they need to Protocol::WATCHERS. A room lives while it has peers or watchers, and EMPTY_GRACE seconds
 * after (the page that created it opens the game, which joins it).
 */
final class RelayServer
{
    /** @var array<int, resource> */
    private array $sockets = [];
    /** @var array<int, array> client state by socket id: buffer, frames, open, room, peer, out, seen */
    private array $clients = [];
    /**
     * @var array<string, array{max: int, meta: array, public: bool, locked: bool, host: int, peers: array<int, int>,
     *      watchers: array<int, int>, info: array, empty: ?float}>
     *      code => room (peer / watcher id => socket id); host -1: no peer; empty: since when nobody is in it
     */
    private array $rooms = [];
    /** Seconds an empty room is kept (a watcher opening the game: the game joins it a moment later). */
    private const EMPTY_GRACE = 90.0;
    private const MAX_WATCHERS = 32;
    private const MAX_INFO = 2048;
    private $server;
    private bool $running = true;

    /**
     * @param callable(string): void|null $log
     * @param \Closure(): array|null $iceServers RTCIceServer[] sent to each client in "hello" (STUN / TURN with
     *        short-lived credentials: Server\TurnCredentials)
     */
    public function __construct(
        private string $address = 'tcp://0.0.0.0:8090',
        private $log = null,
        private int $maxClients = 2000,
        private float $idleTimeout = 60.0,
        private ?\Closure $iceServers = null,
    ) {
    }

    public function run(): void
    {
        $this->server = @stream_socket_server($this->address, $errno, $error);
        if (!$this->server) throw new \RuntimeException("can't listen on {$this->address}: $error");
        stream_set_blocking($this->server, false);
        $this->log("listening on {$this->address}");
        $lastSweep = microtime(true);
        while ($this->running) {
            $this->tick(0.5);
            if (microtime(true) - $lastSweep > 5) {
                $this->sweep();
                $lastSweep = microtime(true);
            }
        }
        foreach (array_keys($this->sockets) as $id) $this->drop($id);
        fclose($this->server);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** One pass of the loop: accepts, reads, writes; waits $timeout seconds at most. */
    public function tick(float $timeout): void
    {
        $read = $this->sockets;
        $read[-1] = $this->server;
        $write = [];
        foreach ($this->clients as $id => $client) {
            if ($client['out'] !== '') $write[$id] = $this->sockets[$id];
        }
        $except = null;
        $seconds = (int)$timeout;
        if (@stream_select($read, $write, $except, $seconds, (int)(($timeout - $seconds) * 1e6)) === false) return;
        foreach ($read as $key => $socket) {
            if ($socket === $this->server) {
                $this->accept();
                continue;
            }
            try {
                $this->read((int)$key);
            } catch (\Throwable $e) {
                // one client's bug never stops the server (and the other rooms)
                $this->log("#$key: " . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
                $this->drop((int)$key);
            }
        }
        foreach ($write as $key => $socket) {
            if (isset($this->clients[(int)$key])) $this->flush((int)$key);
        }
    }

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->server, 0, $peerName);
        if (!$socket) return;
        if (count($this->sockets) >= $this->maxClients) {
            fclose($socket);
            return;
        }
        stream_set_blocking($socket, false);
        $id = (int)$socket;
        $this->sockets[$id] = $socket;
        $this->clients[$id] = ['buffer' => '', 'frames' => new Frames(Protocol::MAX_MESSAGE + 1), 'open' => false,
            'room' => null, 'peer' => -1, 'out' => '', 'seen' => microtime(true), 'name' => $peerName];
    }

    private function read(int $id): void
    {
        $data = @fread($this->sockets[$id], 65536);
        if ($data === '' || $data === false) {
            if ($data === false || feof($this->sockets[$id])) $this->drop($id);
            return;
        }
        $client = &$this->clients[$id];
        $client['seen'] = microtime(true);
        if (!$client['open']) {
            $client['buffer'] .= $data;
            if (strlen($client['buffer']) > 8192) {
                $this->drop($id);
                return;
            }
            $end = strpos($client['buffer'], "\r\n\r\n");
            if ($end === false) return;
            $request = substr($client['buffer'], 0, $end);
            $rest = substr($client['buffer'], $end + 4);
            $client['buffer'] = '';
            unset($client);
            $this->handshake($id, $request);
            if ($rest !== '' && isset($this->clients[$id])) $this->frames($id, $rest);
            return;
        }
        unset($client);
        $this->frames($id, $data);
    }

    private function handshake(int $id, string $request): void
    {
        $lines = explode("\r\n", $request);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $headers[strtolower($name)] = $value;
        }
        $key = $headers['sec-websocket-key'] ?? '';
        if (!str_starts_with($lines[0], 'GET ') || $key === '' || stripos($headers['upgrade'] ?? '', 'websocket') === false) {
            // plain HTTP: a health check; /health?rooms: the public rooms too
            $body = json_encode($this->health(str_contains(explode(' ', $lines[0])[1] ?? '', 'rooms')));
            $this->clients[$id]['out'] = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nAccess-Control-Allow-Origin: *\r\n"
                . "Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
            $this->flush($id);
            $this->drop($id);
            return;
        }
        $this->clients[$id]['open'] = true;
        $this->clients[$id]['out'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . Frames::accept($key) . "\r\n\r\n";
        $hello = ['t' => 'hello', 'v' => Protocol::VERSION];
        if ($this->iceServers !== null) $hello['ice'] = ($this->iceServers)();
        $this->sendJson($id, $hello);
    }

    /** The health check: counts, and with $list the public rooms (code, players, watchers, info). */
    private function health(bool $list): array
    {
        $players = $watchers = 0;
        foreach ($this->rooms as $room) {
            $players += count($room['peers']);
            $watchers += count($room['watchers']);
        }
        $health = ['ok' => true, 'rooms' => count($this->rooms),
            'public' => count(array_filter($this->rooms, fn(array $room) => $room['public'])), 'clients' => count($this->clients),
            'players' => $players, 'watchers' => $watchers];
        if ($list) {
            $health['list'] = [];
            foreach ($this->rooms as $code => $room) {
                if (!$room['public']) continue;
                $health['list'][] = ['room' => $code, 'players' => count($room['peers']), 'watchers' => count($room['watchers']),
                    'max' => $room['max'], 'locked' => $room['locked'], 'meta' => (object)$room['meta'], 'info' => (object)$room['info']];
            }
        }
        return $health;
    }

    private function frames(int $id, string $data): void
    {
        try {
            $messages = $this->clients[$id]['frames']->feed($data);
        } catch (\RuntimeException $e) {
            $this->log("#$id: " . $e->getMessage());
            $this->drop($id);
            return;
        }
        foreach ($messages as [$opcode, $payload]) {
            if (!isset($this->clients[$id])) return;
            match ($opcode) {
                Frames::TEXT => $this->control($id, $payload),
                Frames::BINARY => $this->relay($id, $payload),
                Frames::PING => $this->write($id, Frames::encode(Frames::PONG, $payload, false)),
                Frames::CLOSE => $this->drop($id, true),
                default => null,
            };
        }
    }

    private function control(int $id, string $json): void
    {
        $message = json_decode($json, true);
        if (!is_array($message) || !is_string($message['t'] ?? null)) {
            $this->error($id, 'bad-message', 'not a JSON control message');
            return;
        }
        switch ($message['t']) {
            case 'create':
                $this->create($id, $message, !empty($message['public']), !empty($message['watch']));
                break;
            case 'quick':
                // a public room with the same meta and a free place (the fullest one), or a new one
                $meta = self::meta($message);
                $best = null;
                foreach ($this->rooms as $code => $room) {
                    if (!$room['public'] || $room['locked'] || $room['meta'] !== $meta || count($room['peers']) >= $room['max']) continue;
                    if ($best === null || count($room['peers']) > count($this->rooms[$best]['peers'])) $best = $code;
                }
                if ($best === null || $this->clients[$id]['room'] === $best) {
                    $this->create($id, $message, true);
                    break;
                }
                $this->leave($id);
                $this->enter($id, $best);
                break;
            case 'lock':
                // the host: no more players from "quick" (a match started), or again
                $code = $this->clients[$id]['room'];
                if ($code !== null && $this->rooms[$code]['host'] === $this->clients[$id]['peer']) {
                    $this->rooms[$code]['locked'] = !empty($message['locked']);
                }
                break;
            case 'join':
                $code = strtoupper(trim((string)($message['room'] ?? '')));
                if (!isset($this->rooms[$code])) {
                    $this->error($id, 'no-room', "no room $code");
                    break;
                }
                if (count($this->rooms[$code]['peers']) >= $this->rooms[$code]['max']) {
                    $this->error($id, 'room-full', "room $code is full");
                    break;
                }
                $this->leave($id);
                $this->enter($id, $code);
                break;
            case 'watch':
                // a watcher (a web page): the room, its chat, never a player
                $code = strtoupper(trim((string)($message['room'] ?? '')));
                if (!isset($this->rooms[$code])) {
                    $this->error($id, 'no-room', "no room $code");
                    break;
                }
                if (count($this->rooms[$code]['watchers']) >= self::MAX_WATCHERS) {
                    $this->error($id, 'room-full', "room $code is full");
                    break;
                }
                $this->leave($id);
                $this->enter($id, $code, true);
                break;
            case 'info':
                // what the room list shows (players, match): the host, or a watcher while no peer is in it
                $code = $this->clients[$id]['room'];
                if ($code === null) break;
                $room = $this->rooms[$code];
                $peer = $this->clients[$id]['peer'];
                $allowed = $peer === $room['host'] || ($room['host'] === -1 && $peer >= Protocol::FIRST_WATCHER);
                $info = is_array($message['data'] ?? null) ? $message['data'] : [];
                if ($allowed && strlen(json_encode($info)) <= self::MAX_INFO) $this->rooms[$code]['info'] = $info;
                break;
            case 'leave':
                $this->leave($id);
                break;
            case 'signal':
                $room = $this->clients[$id]['room'];
                $to = $room !== null ? ($this->rooms[$room]['peers'][(int)($message['to'] ?? -1)] ?? null) : null;
                if ($to === null) break;
                $this->sendJson($to, ['t' => 'signal', 'from' => $this->clients[$id]['peer'], 'data' => $message['data'] ?? null]);
                break;
            case 'ping':
                $this->sendJson($id, ['t' => 'pong', 'id' => $message['id'] ?? 0]);
                break;
            default:
                $this->error($id, 'bad-message', "unknown message {$message['t']}");
        }
    }

    private function create(int $id, array $message, bool $public, bool $watch = false): void
    {
        $this->leave($id);
        $code = $this->newCode();
        $max = max(2, min(Protocol::MAX_PEERS, (int)($message['max'] ?? 8)));
        $this->rooms[$code] = ['max' => $max, 'meta' => self::meta($message), 'public' => $public, 'locked' => false,
            'host' => -1, 'peers' => [], 'watchers' => [], 'info' => [], 'empty' => null];
        $this->enter($id, $code, $watch);
        $this->log("#$id created $code" . ($public ? ' (public)' : '') . ($watch ? ' (watching)' : ''));
    }

    private static function meta(array $message): array
    {
        $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
        return strlen(json_encode($meta)) > 4096 ? [] : $meta;
    }

    /**
     * Binary frame [to] payload -> [from] payload: to one peer / watcher, to every other peer (BROADCAST; from a
     * watcher: the other watchers too), or to every watcher (WATCHERS).
     */
    private function relay(int $id, string $frame): void
    {
        $room = $this->clients[$id]['room'];
        if ($room === null || $frame === '') return;
        $to = ord($frame[0]);
        $from = $this->clients[$id]['peer'];
        $out = Frames::encode(Frames::BINARY, chr($from) . substr($frame, 1), false);
        $targets = match (true) {
            $to === Protocol::BROADCAST => $from >= Protocol::FIRST_WATCHER
                ? $this->rooms[$room]['peers'] + $this->rooms[$room]['watchers'] : $this->rooms[$room]['peers'],
            $to === Protocol::WATCHERS => $this->rooms[$room]['watchers'],
            default => array_filter([$to => $this->rooms[$room]['peers'][$to] ?? $this->rooms[$room]['watchers'][$to] ?? null]),
        };
        foreach ($targets as $target) {
            if ($target !== $id) $this->write($target, $out);
        }
    }

    private function enter(int $id, string $code, bool $watch = false): void
    {
        $room = &$this->rooms[$code];
        $room['empty'] = null;
        $list = $watch ? 'watchers' : 'peers';
        $peer = $watch ? Protocol::FIRST_WATCHER : 0;
        while (isset($room[$list][$peer])) $peer++;
        $others = array_keys($room['peers']);
        $watchers = array_keys($room['watchers']);
        $room[$list][$peer] = $id;
        // the first peer of a room (made by a watcher, or left by all its players) is its host
        if (!$watch && $room['host'] === -1) $room['host'] = $peer;
        $this->clients[$id]['room'] = $code;
        $this->clients[$id]['peer'] = $peer;
        $this->sendJson($id, ['t' => 'room', 'room' => $code, 'peer' => $peer, 'host' => $room['host'],
            'peers' => $others, 'watchers' => $watchers, 'watcher' => $watch, 'meta' => $room['meta'], 'max' => $room['max'],
            'public' => $room['public']]);
        $join = ['t' => $watch ? 'watcher-join' : 'peer-join', 'peer' => $peer];
        foreach ($others as $other) $this->sendJson($room['peers'][$other], $join);
        foreach ($watchers as $other) $this->sendJson($room['watchers'][$other], $join);
        unset($room);
    }

    private function leave(int $id): void
    {
        $code = $this->clients[$id]['room'] ?? null;
        if ($code === null || !isset($this->rooms[$code])) return;
        $peer = $this->clients[$id]['peer'];
        unset($this->rooms[$code]['peers'][$peer], $this->rooms[$code]['watchers'][$peer]);
        $this->clients[$id]['room'] = null;
        $this->clients[$id]['peer'] = -1;
        $peers = $this->rooms[$code]['peers'];
        $everybody = $peers + $this->rooms[$code]['watchers'];
        // the new host is chosen before telling anybody: a write that fails drops that peer, which leaves too
        // (and may empty the room) while we are still in this loop
        $hostLeft = $this->rooms[$code]['host'] === $peer;
        if ($hostLeft) $this->rooms[$code]['host'] = $peers ? min(array_keys($peers)) : -1;
        if (!$peers) $this->rooms[$code]['locked'] = false;
        if (!$everybody) {
            // kept a moment (sweep()): the game opened by the page that made it joins it
            $this->rooms[$code]['empty'] = microtime(true);
            return;
        }
        foreach ($everybody as $target) $this->sendJson($target, ['t' => 'peer-leave', 'peer' => $peer]);
        if ($hostLeft && isset($this->rooms[$code])) {
            foreach ($this->rooms[$code]['peers'] + $this->rooms[$code]['watchers'] as $target) {
                if (isset($this->rooms[$code])) $this->sendJson($target, ['t' => 'host', 'peer' => $this->rooms[$code]['host']]);
            }
        }
    }

    private function newCode(): string
    {
        $alphabet = Protocol::ROOM_ALPHABET;
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        } while (isset($this->rooms[$code]));
        return $code;
    }

    private function error(int $id, string $code, string $message): void
    {
        $this->sendJson($id, ['t' => 'error', 'code' => $code, 'message' => $message]);
    }

    private function sendJson(int $id, array $message): void
    {
        $this->write($id, Frames::encode(Frames::TEXT, json_encode($message), false));
    }

    private function write(int $id, string $bytes): void
    {
        if (!isset($this->clients[$id])) return;
        $this->clients[$id]['out'] .= $bytes;
        if (strlen($this->clients[$id]['out']) > 4 << 20) {
            $this->log("#$id: too slow, dropped");
            $this->drop($id);
            return;
        }
        $this->flush($id);
    }

    private function flush(int $id): void
    {
        $out = $this->clients[$id]['out'];
        if ($out === '') return;
        $written = @fwrite($this->sockets[$id], $out);
        if ($written === false) {
            $this->drop($id);
            return;
        }
        $this->clients[$id]['out'] = (string)substr($out, $written);
    }

    private function drop(int $id, bool $closeFrame = false): void
    {
        if (!isset($this->clients[$id])) return;
        $this->leave($id);
        if ($closeFrame) @fwrite($this->sockets[$id], Frames::encode(Frames::CLOSE, '', false));
        @fclose($this->sockets[$id]);
        unset($this->sockets[$id], $this->clients[$id]);
    }

    /** Connections silent for too long (the clients ping every few seconds), rooms empty for too long. */
    private function sweep(): void
    {
        $now = microtime(true);
        foreach ($this->clients as $id => $client) {
            if ($now - $client['seen'] > ($client['open'] ? $this->idleTimeout : 10)) $this->drop($id);
        }
        foreach ($this->rooms as $code => $room) {
            if ($room['empty'] !== null && $now - $room['empty'] > self::EMPTY_GRACE) {
                unset($this->rooms[$code]);
                $this->log("room $code closed");
            }
        }
    }

    private function log(string $message): void
    {
        if ($this->log) ($this->log)(date('H:i:s') . ' ' . $message);
    }
}
