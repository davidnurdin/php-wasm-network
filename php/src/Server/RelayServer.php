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
 */
final class RelayServer
{
    /** @var array<int, resource> */
    private array $sockets = [];
    /** @var array<int, array> client state by socket id: buffer, frames, open, room, peer, out, seen */
    private array $clients = [];
    /** @var array<string, array{max: int, meta: array, host: int, peers: array<int, int>}> code => room (peer id => socket id) */
    private array $rooms = [];
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
            } else {
                $this->read((int)$key);
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
            // plain HTTP: a health check
            $body = json_encode(['ok' => true, 'rooms' => count($this->rooms), 'clients' => count($this->clients)]);
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
                $this->leave($id);
                $code = $this->newCode();
                $max = max(2, min(Protocol::MAX_PEERS, (int)($message['max'] ?? 8)));
                $meta = is_array($message['meta'] ?? null) ? $message['meta'] : [];
                if (strlen(json_encode($meta)) > 4096) $meta = [];
                $this->rooms[$code] = ['max' => $max, 'meta' => $meta, 'host' => 0, 'peers' => []];
                $this->enter($id, $code);
                $this->log("#$id created $code");
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

    /** Binary frame [to] payload -> [from] payload to one peer or to every other peer of the room. */
    private function relay(int $id, string $frame): void
    {
        $room = $this->clients[$id]['room'];
        if ($room === null || $frame === '') return;
        $to = ord($frame[0]);
        $out = Frames::encode(Frames::BINARY, chr($this->clients[$id]['peer']) . substr($frame, 1), false);
        foreach ($this->rooms[$room]['peers'] as $peer => $target) {
            if ($target === $id || ($to !== Protocol::BROADCAST && $peer !== $to)) continue;
            $this->write($target, $out);
        }
    }

    private function enter(int $id, string $code): void
    {
        $room = &$this->rooms[$code];
        $peer = 0;
        while (isset($room['peers'][$peer])) $peer++;
        $others = array_keys($room['peers']);
        $room['peers'][$peer] = $id;
        $this->clients[$id]['room'] = $code;
        $this->clients[$id]['peer'] = $peer;
        $this->sendJson($id, ['t' => 'room', 'room' => $code, 'peer' => $peer, 'host' => $room['host'],
            'peers' => $others, 'meta' => $room['meta'], 'max' => $room['max']]);
        foreach ($others as $other) $this->sendJson($room['peers'][$other], ['t' => 'peer-join', 'peer' => $peer]);
    }

    private function leave(int $id): void
    {
        $code = $this->clients[$id]['room'] ?? null;
        if ($code === null || !isset($this->rooms[$code])) return;
        $peer = $this->clients[$id]['peer'];
        $room = &$this->rooms[$code];
        unset($room['peers'][$peer]);
        $this->clients[$id]['room'] = null;
        $this->clients[$id]['peer'] = -1;
        if (!$room['peers']) {
            unset($this->rooms[$code]);
            $this->log("room $code closed");
            return;
        }
        foreach ($room['peers'] as $target) $this->sendJson($target, ['t' => 'peer-leave', 'peer' => $peer]);
        if ($room['host'] === $peer) {
            $room['host'] = min(array_keys($room['peers']));
            foreach ($room['peers'] as $target) $this->sendJson($target, ['t' => 'host', 'peer' => $room['host']]);
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

    /** Connections silent for too long (the clients ping every few seconds). */
    private function sweep(): void
    {
        $now = microtime(true);
        foreach ($this->clients as $id => $client) {
            if ($now - $client['seen'] > ($client['open'] ? $this->idleTimeout : 10)) $this->drop($id);
        }
    }

    private function log(string $message): void
    {
        if ($this->log) ($this->log)(date('H:i:s') . ' ' . $message);
    }
}
