<?php

namespace PhpWasmNetwork;

use PhpWasmNetwork\WebSocket\Frames;

/**
 * Connection for native PHP (CLI, php-sdl desktop): a WebSocket to the relay server, without blocking after
 * the connection (poll() reads what is there). Everything goes through the relay: there is no WebRTC in PHP;
 * the browser peers talking to a native peer use the relay for it.
 */
final class NativeConnection implements Connection
{
    /** @var resource|null */
    private $socket = null;
    private int $state = self::CONNECTING;
    private string $handshakeBuffer = '';
    private string $expectedAccept = '';
    private Frames $frames;
    private string $out = '';
    /** @var Event[] */
    private array $events = [];
    private array $pings = [];
    private int $pingId = 0;
    private float $lastPing = 0;

    /**
     * @param string $url ws://host:port/path or wss://host/path
     * @param float $timeout seconds to connect (TCP + TLS)
     */
    public function __construct(private string $url, float $timeout = 5.0)
    {
        $this->frames = new Frames(Protocol::MAX_MESSAGE + 1);
        $parts = parse_url($url);
        $secure = ($parts['scheme'] ?? 'ws') === 'wss';
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client(($secure ? 'ssl' : 'tcp') . "://$host:$port", $errno, $error, $timeout,
            STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            $this->fail("can't connect to $url: $error");
            return;
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $key = base64_encode(random_bytes(16));
        $this->expectedAccept = Frames::accept($key);
        $hostHeader = $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->out = "GET $path HTTP/1.1\r\nHost: $hostHeader\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
        $this->flush();
    }

    public function createRoom(int $maxPeers = 8, array $meta = [], bool $public = false): void
    {
        $this->control(['t' => 'create', 'max' => $maxPeers, 'meta' => $meta ?: new \stdClass(), 'public' => $public]);
    }

    public function quickJoin(int $maxPeers = 8, array $meta = []): void
    {
        $this->control(['t' => 'quick', 'max' => $maxPeers, 'meta' => $meta ?: new \stdClass()]);
    }

    public function lockRoom(bool $locked): void
    {
        $this->control(['t' => 'lock', 'locked' => $locked]);
    }

    public function joinRoom(string $code): void
    {
        $this->control(['t' => 'join', 'room' => $code]);
    }

    public function leaveRoom(): void
    {
        $this->control(['t' => 'leave']);
    }

    public function setRoomInfo(array $info): void
    {
        $this->control(['t' => 'info', 'data' => $info ?: new \stdClass()]);
    }

    /** Follows the room $code as a watcher (Protocol: watch), or creates one ($code null) to watch. */
    public function watchRoom(?string $code, int $maxPeers = 8, array $meta = [], bool $public = false): void
    {
        $this->control($code !== null ? ['t' => 'watch', 'room' => $code]
            : ['t' => 'create', 'max' => $maxPeers, 'meta' => $meta ?: new \stdClass(), 'public' => $public, 'watch' => true]);
    }

    public function send(string $data, ?int $to = null, bool $reliable = true): bool
    {
        if ($this->state !== self::OPEN || strlen($data) > Protocol::MAX_MESSAGE) return false;
        $this->write(Frames::encode(Frames::BINARY, chr($to ?? Protocol::BROADCAST) . $data, true));
        return true;
    }

    public function ping(): void
    {
        $this->pings[++$this->pingId] = microtime(true);
        $this->control(['t' => 'ping', 'id' => $this->pingId]);
    }

    public function poll(): array
    {
        if ($this->socket !== null) {
            $this->flush();
            while (is_resource($this->socket) && ($data = @fread($this->socket, 65536)) !== false && $data !== '') {
                $this->receive($data);
            }
            if (is_resource($this->socket) && feof($this->socket)) $this->closed('connection lost');
            // keeps the connection alive (the server drops silent clients)
            if ($this->state === self::OPEN && microtime(true) - $this->lastPing > 15) {
                $this->lastPing = microtime(true);
                $this->write(Frames::encode(Frames::PING, '', true));
            }
        }
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function state(): int
    {
        return $this->state;
    }

    public function close(): void
    {
        if ($this->socket === null) return;
        if ($this->state === self::OPEN) {
            $this->write(Frames::encode(Frames::CLOSE, '', true));
        }
        $this->closed('closed');
    }

    private function receive(string $data): void
    {
        if ($this->state === self::CONNECTING) {
            $this->handshakeBuffer .= $data;
            $end = strpos($this->handshakeBuffer, "\r\n\r\n");
            if ($end === false) return;
            $head = substr($this->handshakeBuffer, 0, $end);
            $data = substr($this->handshakeBuffer, $end + 4);
            $this->handshakeBuffer = '';
            if (!preg_match('#^HTTP/1\.1 101#', $head) || stripos($head, $this->expectedAccept) === false) {
                $this->fail('not a WebSocket server: ' . strtok($head, "\r\n"));
                return;
            }
            $this->state = self::OPEN;
            $this->lastPing = microtime(true);
            $this->events[] = new Event(Event::OPEN);
            if ($data === '') return;
        }
        try {
            $messages = $this->frames->feed($data);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
            return;
        }
        foreach ($messages as [$opcode, $payload]) {
            match ($opcode) {
                Frames::BINARY => $payload !== '' && ($this->events[] = new Event(Event::MESSAGE, ord($payload[0]), substr($payload, 1))),
                Frames::TEXT => $this->onControl($payload),
                Frames::PING => $this->write(Frames::encode(Frames::PONG, $payload, true)),
                Frames::CLOSE => $this->closed('closed by the server'),
                default => null,
            };
        }
    }

    private function onControl(string $json): void
    {
        $m = json_decode($json, true);
        if (!is_array($m)) return;
        $event = match ($m['t'] ?? '') {
            'room' => new Event(Event::ROOM, -1, $json),
            'peer-join' => new Event(Event::PEER_JOIN, (int)$m['peer']),
            'peer-leave' => new Event(Event::PEER_LEAVE, (int)$m['peer']),
            'host' => new Event(Event::HOST, (int)$m['peer']),
            'error' => new Event(Event::ERROR, -1, $json),
            'pong' => isset($this->pings[$m['id'] ?? 0])
                ? new Event(Event::PONG, -1, json_encode(['ms' => round((microtime(true) - $this->pings[$m['id']]) * 1000, 1)]))
                : null,
            default => null,   // hello, signal (WebRTC: browsers only)
        };
        if (($m['t'] ?? '') === 'pong') unset($this->pings[$m['id'] ?? 0]);
        if ($event) $this->events[] = $event;
    }

    private function control(array $message): void
    {
        if ($this->state !== self::OPEN) {
            $this->events[] = new Event(Event::ERROR, -1, json_encode(['code' => 'not-open', 'message' => 'not connected']));
            return;
        }
        $this->write(Frames::encode(Frames::TEXT, json_encode($message), true));
    }

    private function write(string $bytes): void
    {
        $this->out .= $bytes;
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->socket === null || $this->out === '') return;
        $written = @fwrite($this->socket, $this->out);
        if ($written === false) {
            $this->closed('write error');
            return;
        }
        $this->out = (string)substr($this->out, $written);
    }

    private function fail(string $message): void
    {
        $this->events[] = new Event(Event::ERROR, -1, json_encode(['code' => 'connection', 'message' => $message]));
        $this->closed($message);
    }

    private function closed(string $reason): void
    {
        if ($this->state === self::CLOSED) return;
        if ($this->socket !== null) @fclose($this->socket);
        $this->socket = null;
        $this->state = self::CLOSED;
        $this->events[] = new Event(Event::CLOSE, -1, $reason);
    }
}
