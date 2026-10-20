<?php
// Chat in a room, native PHP or php-wasm: php examples/chat/chat.php ws://localhost:8090 [ROOM]
// Without ROOM it creates one and prints its code. Type a line + Enter to send it to the room.

require __DIR__ . '/../../vendor/autoload.php';

use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

$net = Network::connect($argv[1] ?? 'ws://localhost:8090');
$room = $argv[2] ?? null;
stream_set_blocking(STDIN, false);
while ($net->state() !== $net::CLOSED) {
    foreach ($net->poll() as $event) {
        switch ($event->type) {
            case Event::OPEN:
                $room ? $net->joinRoom($room) : $net->createRoom(8, ['app' => 'chat']);
                break;
            case Event::ROOM:
                $info = $event->info();
                echo "room {$info['room']}: I am #{$info['peer']}, host #{$info['host']}, others: " . implode(', ', $info['peers']) . "\n";
                break;
            case Event::MESSAGE:
                echo "#{$event->from}: {$event->data}\n";
                break;
            default:
                echo "[{$event->name()}] " . ($event->from >= 0 ? "#{$event->from} " : '') . $event->data . "\n";
        }
    }
    if (($line = fgets(STDIN)) !== false && ($line = trim($line)) !== '') $net->send($line);
    usleep(16000);
}
