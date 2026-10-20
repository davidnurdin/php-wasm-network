<?php

namespace PhpWasmNetwork\Server;

/**
 * Short-lived TURN credentials (the "TURN REST API" of coturn --use-auth-secret --static-auth-secret=...):
 * username = "<expiry timestamp>:<name>", credential = base64(HMAC-SHA1(secret, username)). The secret stays on
 * the server; a credential read in a page only works for $ttl seconds.
 */
final class TurnCredentials
{
    /**
     * @param string[] $turnUrls e.g. ['turn:example.org:3478?transport=udp', 'turn:example.org:3478?transport=tcp']
     * @param string[] $stunUrls e.g. ['stun:example.org:3478']
     */
    public function __construct(
        private string $secret,
        private array $turnUrls,
        private array $stunUrls = [],
        private int $ttl = 6 * 3600,
        private string $name = 'php-wasm-network',
    ) {
    }

    /** From the environment: TURN_SECRET, TURN_URLS and STUN_URLS (comma separated), TURN_TTL; null without TURN_SECRET. */
    public static function fromEnv(): ?self
    {
        $secret = (string)getenv('TURN_SECRET');
        if ($secret === '') return null;
        $list = fn(string $name) => array_values(array_filter(array_map('trim', explode(',', (string)getenv($name)))));
        return new self($secret, $list('TURN_URLS'), $list('STUN_URLS'), (int)(getenv('TURN_TTL') ?: 6 * 3600));
    }

    /** RTCIceServer[] */
    public function iceServers(): array
    {
        $servers = [];
        if ($this->stunUrls) $servers[] = ['urls' => $this->stunUrls];
        if ($this->turnUrls) {
            $username = (time() + $this->ttl) . ':' . $this->name;
            $servers[] = ['urls' => $this->turnUrls, 'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $this->secret, true))];
        }
        return $servers;
    }
}
