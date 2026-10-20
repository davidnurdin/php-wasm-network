<?php
// Native end-to-end test: starts the relay server, connects clients, checks rooms / relay / host / errors.
// php tests/native-test.php
require __DIR__ . '/../vendor/autoload.php';

use PhpWasmNetwork\Event;
use PhpWasmNetwork\NativeConnection;

$port = 18000 + random_int(0, 999);
$server = proc_open([PHP_BINARY, __DIR__ . '/../server/bin/server.php', "tcp://127.0.0.1:$port"], [2 => ['file', '/dev/null', 'w']], $pipes);
usleep(300000);
$failures = 0;
function check(bool $ok, string $what): void { global $failures; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $failures++; }
/** Polls every connection until $until returns true (or 2 s). @return Event[][] events by name */
function pump(array $conns, ?callable $until = null, float $max = 2.0): array {
    $all = array_fill_keys(array_keys($conns), []);
    $end = microtime(true) + $max;
    do {
        foreach ($conns as $k => $c) foreach ($c->poll() as $e) $all[$k][] = $e;
        if ($until && $until($all)) break;
        usleep(5000);
    } while (microtime(true) < $end);
    return $all;
}
function of(array $events, int $type): array { return array_values(array_filter($events, fn($e) => $e->type === $type)); }

$url = "ws://127.0.0.1:$port/ws";
$a = new NativeConnection($url); $b = new NativeConnection($url); $c = new NativeConnection($url);
$ev = pump(compact('a', 'b', 'c'), fn($all) => count(of($all['a'], Event::OPEN)) && count(of($all['b'], Event::OPEN)) && count(of($all['c'], Event::OPEN)));
check(count(of($ev['c'], Event::OPEN)) === 1, 'three clients connected');

$a->createRoom(3, ['mode' => 'race']);
$ev = pump(['a' => $a], fn($all) => of($all['a'], Event::ROOM));
$room = of($ev['a'], Event::ROOM)[0]->info();
check(strlen($room['room']) === 4 && $room['peer'] === 0 && $room['host'] === 0, "room {$room['room']} created, host #0");

$b->joinRoom(strtolower($room['room']));
$ev = pump(['a' => $a, 'b' => $b], fn($all) => of($all['b'], Event::ROOM) && of($all['a'], Event::PEER_JOIN));
$rb = of($ev['b'], Event::ROOM)[0]->info();
check($rb['peer'] === 1 && $rb['peers'] === [0] && $rb['meta'] === ['mode' => 'race'], 'b joined as #1, sees #0 and the meta');
check(of($ev['a'], Event::PEER_JOIN)[0]->from === 1, 'a told that #1 joined');

$c->joinRoom($room['room']);
pump(['a' => $a, 'b' => $b, 'c' => $c], fn($all) => of($all['c'], Event::ROOM));
$d = new NativeConnection($url);
$ev = pump(['d' => $d], fn($all) => of($all['d'], Event::OPEN));
$d->joinRoom($room['room']);
$ev = pump(['d' => $d], fn($all) => of($all['d'], Event::ERROR));
check((of($ev['d'], Event::ERROR)[0] ?? null)?->info()['code'] === 'room-full', 'a 4th peer gets room-full');
$d->joinRoom('ZZZZ');
$ev = pump(['d' => $d], fn($all) => of($all['d'], Event::ERROR));
check((of($ev['d'], Event::ERROR)[0] ?? null)?->info()['code'] === 'no-room', 'unknown room: no-room');

$bin = random_bytes(1000) . "\0\xff";
$a->send($bin);
$ev = pump(['b' => $b, 'c' => $c], fn($all) => of($all['b'], Event::MESSAGE) && of($all['c'], Event::MESSAGE));
check(of($ev['b'], Event::MESSAGE)[0]->data === $bin && of($ev['b'], Event::MESSAGE)[0]->from === 0, 'broadcast binary reaches b from #0');
check(of($ev['c'], Event::MESSAGE)[0]->data === $bin, 'broadcast reaches c');

$c->send('only for a', 0);
$ev = pump(['a' => $a, 'b' => $b], fn($all) => of($all['a'], Event::MESSAGE), 0.5);
check((of($ev['a'], Event::MESSAGE)[0] ?? null)?->data === 'only for a' && of($ev['a'], Event::MESSAGE)[0]->from === 2, 'direct message to #0 from #2');
check(of($ev['b'], Event::MESSAGE) === [], 'b does not get the direct message');

$big = str_repeat('x', 60000);
$b->send($big, 2);
$ev = pump(['c' => $c], fn($all) => of($all['c'], Event::MESSAGE));
check((of($ev['c'], Event::MESSAGE)[0] ?? null)?->data === $big, '60 KB message');

$n = 300;
for ($i = 0; $i < $n; $i++) $a->send(pack('N', $i), 1);
$ev = pump(['b' => $b], fn($all) => count(of($all['b'], Event::MESSAGE)) >= $n);
$got = array_map(fn($e) => unpack('N', $e->data)[1], of($ev['b'], Event::MESSAGE));
check($got === range(0, $n - 1), "$n small messages in order");

$a->ping();
$ev = pump(['a' => $a], fn($all) => of($all['a'], Event::PONG));
check(isset(of($ev['a'], Event::PONG)[0]->info()['ms']), 'ping: ' . (of($ev['a'], Event::PONG)[0]->info()['ms'] ?? '?') . ' ms');

$a->close();
$ev = pump(['b' => $b, 'c' => $c], fn($all) => of($all['b'], Event::HOST) && of($all['c'], Event::HOST));
check((of($ev['b'], Event::PEER_LEAVE)[0] ?? null)?->from === 0, 'host leaves: b told');
check((of($ev['c'], Event::HOST)[0] ?? null)?->from === 1, 'new host is #1');

$c->joinRoom($room['room']);   // re-join the same room (leave + enter): gets the free id 0
$ev = pump(['c' => $c], fn($all) => of($all['c'], Event::ROOM));
check((of($ev['c'], Event::ROOM)[0] ?? null)?->info()['peer'] === 0, 're-join takes the lowest free id');

$health = @file_get_contents("http://127.0.0.1:$port/health");
check(($health = json_decode((string)$health, true))['ok'] ?? false, 'HTTP health check: ' . json_encode($health));

// public rooms: quick joins the fullest open one with the same meta, or creates one; a locked one is skipped
$p1 = new NativeConnection($url); $p2 = new NativeConnection($url); $p3 = new NativeConnection($url); $p4 = new NativeConnection($url);
$all = compact('p1', 'p2', 'p3', 'p4');
pump($all, fn($ev) => count(array_filter($ev, fn($e) => of($e, Event::OPEN))) === 4);
$p1->quickJoin(3, ['game' => 'x']);
$r1 = of(pump(['p1' => $p1], fn($ev) => of($ev['p1'], Event::ROOM))['p1'], Event::ROOM)[0]->info();
check($r1['public'] === true && $r1['peer'] === 0, "quick with no public room: creates {$r1['room']}, public");
$p2->quickJoin(3, ['game' => 'y']);
$r2 = of(pump(['p2' => $p2], fn($ev) => of($ev['p2'], Event::ROOM))['p2'], Event::ROOM)[0]->info();
check($r2['room'] !== $r1['room'], 'quick with another meta: another room');
$a->createRoom(3, ['game' => 'x']);   // private: never joined by quick
pump(['a' => $a], fn($ev) => of($ev['a'], Event::ROOM));
$p3->quickJoin(3, ['game' => 'x']);
$r3 = of(pump(['p3' => $p3], fn($ev) => of($ev['p3'], Event::ROOM))['p3'], Event::ROOM)[0]->info();
check($r3['room'] === $r1['room'] && $r3['peer'] === 1, 'quick joins the public room with the same meta');
$p3->lockRoom(true);   // not the host: ignored
$p1->lockRoom(true);
usleep(100000);
$p4->quickJoin(3, ['game' => 'x']);
$r4 = of(pump(['p4' => $p4], fn($ev) => of($ev['p4'], Event::ROOM))['p4'], Event::ROOM)[0]->info();
check($r4['room'] !== $r1['room'], 'a locked room is skipped by quick');
$p1->lockRoom(false);
$p2->quickJoin(3, ['game' => 'x']);
$r5 = of(pump(['p2' => $p2], fn($ev) => of($ev['p2'], Event::ROOM))['p2'], Event::ROOM)[0]->info();
check($r5['room'] === $r1['room'], 'unlocked: the fullest public room is joined first');
foreach ($all as $conn) $conn->close();

$b->close(); $c->close(); $d->close();
proc_terminate($server);
echo $failures ? "$failures FAILED\n" : "all passed\n";
exit($failures ? 1 : 0);
