<?php

namespace PhpWasmNetwork\WebSocket;

/**
 * WebSocket frames (RFC 6455), for the relay server and the native client: no dependency.
 * Fragmented messages are put back together; control frames (ping / pong / close) are returned as they are.
 */
final class Frames
{
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private string $buffer = '';
    private ?int $fragmentOpcode = null;
    private string $fragments = '';

    public function __construct(private int $maxMessage = 1 << 20)
    {
    }

    /** A frame; $mask: true from a client (required by the RFC), false from the server. */
    public static function encode(int $opcode, string $payload, bool $mask): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        $maskBit = $mask ? 0x80 : 0;
        if ($length < 126) {
            $head .= chr($maskBit | $length);
        } elseif ($length < 65536) {
            $head .= chr($maskBit | 126) . pack('n', $length);
        } else {
            $head .= chr($maskBit | 127) . pack('NN', 0, $length);
        }
        if (!$mask) return $head . $payload;
        $key = random_bytes(4);
        return $head . $key . ($payload ^ str_repeat($key, intdiv($length, 4) + 1));
    }

    /**
     * Adds received bytes; returns the complete messages [opcode, payload].
     * @throws \RuntimeException on a protocol error (too big, bad frame): close the connection
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];
        while (($frame = $this->next()) !== null) {
            [$fin, $opcode, $payload] = $frame;
            if ($opcode >= 0x8) {
                $messages[] = [$opcode, $payload];   // control frames are never fragmented
                continue;
            }
            if ($opcode !== 0) {
                if ($this->fragmentOpcode !== null) throw new \RuntimeException('new message inside a fragmented one');
                if ($fin) {
                    $messages[] = [$opcode, $payload];
                    continue;
                }
                $this->fragmentOpcode = $opcode;
                $this->fragments = $payload;
                continue;
            }
            if ($this->fragmentOpcode === null) throw new \RuntimeException('continuation without a message');
            $this->fragments .= $payload;
            if (strlen($this->fragments) > $this->maxMessage) throw new \RuntimeException('message too big');
            if ($fin) {
                $messages[] = [$this->fragmentOpcode, $this->fragments];
                $this->fragmentOpcode = null;
                $this->fragments = '';
            }
        }
        return $messages;
    }

    /** @return array{0: bool, 1: int, 2: string}|null [fin, opcode, unmasked payload] */
    private function next(): ?array
    {
        $available = strlen($this->buffer);
        if ($available < 2) return null;
        $b0 = ord($this->buffer[0]);
        $b1 = ord($this->buffer[1]);
        $length = $b1 & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if ($available < 4) return null;
            $length = unpack('n', $this->buffer, 2)[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($available < 10) return null;
            [, $high, $low] = unpack('N2', $this->buffer, 2);
            if ($high !== 0) throw new \RuntimeException('message too big');
            $length = $low;
            $offset = 10;
        }
        if ($length > $this->maxMessage) throw new \RuntimeException('message too big');
        $masked = ($b1 & 0x80) !== 0;
        $key = '';
        if ($masked) {
            if ($available < $offset + 4) return null;
            $key = substr($this->buffer, $offset, 4);
            $offset += 4;
        }
        if ($available < $offset + $length) return null;
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);
        if ($masked && $length > 0) $payload ^= str_repeat($key, intdiv($length, 4) + 1);
        return [($b0 & 0x80) !== 0, $b0 & 0x0F, substr($payload, 0, $length)];
    }

    /** Sec-WebSocket-Accept for a Sec-WebSocket-Key. */
    public static function accept(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }
}
