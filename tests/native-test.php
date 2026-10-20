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

// watchers (a web page next to the game): ids from 128, never host, not in the broadcasts of the peers
$w1 = new NativeConnection($url); $w2 = new NativeConnection($url); $g1 = new NativeConnection($url); $g2 = new NativeConnection($url);
$all = compact('w1', 'w2', 'g1', 'g2');
pump($all, fn($ev) => count(array_filter($ev, fn($e) => of($e, Event::OPEN))) === 4);
$w1->watchRoom(null, 6, ['game' => 'w'], true);
$rw = of(pump(['w1' => $w1], fn($ev) => of($ev['w1'], Event::ROOM))['w1'], Event::ROOM)[0]->info();
check($rw['peer'] === 128 && $rw['host'] === -1 && $rw['watcher'] === true, "a watcher creates {$rw['room']}: #128, no host");
$w1->setRoomInfo(['names' => [], 'mode' => 'race']);
$w2->watchRoom($rw['room']);
$g1->joinRoom($rw['room']);
$ev = pump($all, fn($ev) => of($ev['g1'], Event::ROOM) && of($ev['w2'], Event::ROOM));
$rg = of($ev['g1'], Event::ROOM)[0]->info();
check($rg['peer'] === 0 && $rg['host'] === 0 && $rg['watchers'] === [128, 129], 'the first player of a watched room is #0 and the host, sees the watchers');
$g2->joinRoom($rw['room']);
pump($all, fn($ev) => of($ev['g2'], Event::ROOM));
$g1->send('peers only');
$g1->send('watchers only', 254);
$w1->send('from a watcher');
$ev = pump($all, fn($ev) => count(of($ev['w2'], Event::MESSAGE)) >= 2 && count(of($ev['g2'], Event::MESSAGE)) >= 2, 0.6);
// two senders: no order between them
$texts = function (string $k) use (&$ev) { $t = array_map(fn($e) => $e->from . ':' . $e->data, of($ev[$k], Event::MESSAGE)); sort($t); return $t; };
check($texts('g2') === ['0:peers only', '128:from a watcher'], 'a peer gets the broadcasts of peers and watchers: ' . json_encode($texts('g2')));
check($texts('w2') === ['0:watchers only', '128:from a watcher'], 'a watcher gets WATCHERS and the other watchers: ' . json_encode($texts('w2')));
$list = json_decode((string)@file_get_contents("http://127.0.0.1:$port/health?rooms"), true);
$listed = array_values(array_filter($list['list'] ?? [], fn($r) => $r['room'] === $rw['room']))[0] ?? null;
check($listed !== null && $listed['players'] === 2 && $listed['watchers'] === 2 && $listed['info'] === ['names' => [], 'mode' => 'race'],
    '/health?rooms lists the public room with its info: ' . json_encode($listed));
$g1->setRoomInfo(['names' => ['A', 'B']]);
$w2->setRoomInfo(['names' => ['hack']]);   // a watcher, the room has a host: ignored
usleep(150000);
$list = json_decode((string)@file_get_contents("http://127.0.0.1:$port/health?rooms"), true);
$listed = array_values(array_filter($list['list'] ?? [], fn($r) => $r['room'] === $rw['room']))[0] ?? null;
check(($listed['info'] ?? null) === ['names' => ['A', 'B']], 'info: the host sets it, a watcher can not');
$w1->close();
$ev = pump($all, fn($ev) => of($ev['g1'], Event::PEER_LEAVE));
check((of($ev['g1'], Event::PEER_LEAVE)[0] ?? null)?->from === 128, 'a watcher leaving: peer-leave #128');
$g1->close(); $g2->close();
$ev = pump($all, fn($ev) => count(of($ev['w2'], Event::HOST)) >= 2);
check((of($ev['w2'], Event::HOST)[1] ?? null)?->from === -1, 'no peer left: host -1');
$w2->close();
usleep(200000);
$g3 = new NativeConnection($url);
pump(['g3' => $g3], fn($ev) => of($ev['g3'], Event::OPEN));
$g3->joinRoom($rw['room']);
$ev = pump(['g3' => $g3], fn($ev) => of($ev['g3'], Event::ROOM) || of($ev['g3'], Event::ERROR));
check((of($ev['g3'], Event::ROOM)[0] ?? null)?->info()['host'] === 0, 'an empty room is kept a moment: the game joins it and is the host');
$g3->close();

$b->close(); $c->close(); $d->close();
proc_terminate($server);
echo $failures ? "$failures FAILED\n" : "all passed\n";
exit($failures ? 1 : 0);
