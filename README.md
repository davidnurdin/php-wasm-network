# php-wasm-network

Le réseau pour PHP **dans le navigateur** ([php-wasm](https://github.com/davidnurdin/php-wasm-sdl)) et en
**PHP natif**, avec la même API. Transports possibles : **WebSocket**, via un serveur relais, et **WebRTC**
(DataChannels pair-à-pair, fiables ou non). Le serveur, en PHP sans dépendance, fait à la fois salon (lobby),
relais et signalisation WebRTC.

Écrit pour le multijoueur en ligne de [Sonic PHP](https://github.com/davidnurdin/sonicGamePhp), mais
utilisable par n'importe quelle application PHP.

```
 PHP (jeu)  ──  PhpWasmNetwork\Network::connect()
                  │
                  ├─ navigateur : WasmConnection ─ extension wasmnet (C) ─ library_wasmnet.js ─┐
                  │                                                                         window.PhpWasmNetwork
                  │                                                                         (php-wasm-network.js)
                  │                                                                          │            │
                  │                                                                     WebSocket     WebRTC
                  │                                                                          │      (pair-à-pair)
                  └─ natif : NativeConnection ──────────── WebSocket ─────────────┐          │            │
                                                                                  ▼          ▼            ▼
                                                                    serveur relais (server/bin/server.php)  autres pairs
                                                                    salons · relais · signalisation
```

## Pourquoi cette architecture

- PHP en wasm n'a pas de vrais sockets, et sa boucle tourne dans `requestAnimationFrame`. Tout appel
  bloquant gèle la page. Le pont est donc **non bloquant** : le JS reçoit les messages et les met en file,
  et PHP les lit à chaque frame (`poll()`).
- Le moteur wasm ne contient qu'une **colle mince** : l'extension `ext/wasmnet` et
  `js/library_wasmnet.js`. Toute la logique réseau du navigateur est dans `js/php-wasm-network.js`, chargé
  par la page : on la corrige sans recompiler le moteur.
- Les sockets POSIX d'emscripten, avec websockify en face, ont été écartés. Ils seraient bloquants dans la
  boucle de jeu, ne passent que par TCP (pas de WebRTC) et demandent des options de compilation fragiles.
- Le PHP natif n'a pas de WebRTC : un pair natif passe toujours par le relais. Les navigateurs du même
  salon lui parlent alors par le relais, et entre eux en direct.

## API PHP

```php
use PhpWasmNetwork\{Network, Event};

$net = Network::connect('wss://net.example.org/ws', ['transport' => 'webrtc']); // 'ws' par défaut
// à chaque frame :
foreach ($net->poll() as $event) {
    match ($event->type) {
        Event::OPEN => $net->createRoom(4, ['mode' => 'race']),     // ou $net->joinRoom('ABCD')
        Event::ROOM => print("salon {$event->info()['room']}, je suis #{$event->info()['peer']}\n"),
        Event::PEER_JOIN, Event::PEER_LEAVE => null,                 // $event->from
        Event::MESSAGE => handle($event->from, $event->data),        // données binaires
        Event::PEER_TRANSPORT => null,                               // 'webrtc' / 'relay' vers $event->from
        Event::HOST => null,                                         // l'hôte est parti : $event->from le remplace
        Event::ERROR => print($event->info()['code']),               // no-room, room-full, connection...
        Event::CLOSE => null,
        default => null,
    };
}
$net->send($bytes);                    // à tout le salon
$net->send($bytes, 2);                 // au pair #2
$net->send($state, null, false);       // non fiable : peut se perdre, n'arrive jamais en retard (WebRTC)
$net->ping();                          // Event::PONG ['ms' => ...]
```

| Méthode | |
|---|---|
| `Network::connect(string $url, array $options)` | `transport` : `ws` ou `webrtc` ; `iceServers` : serveurs STUN/TURN ; `timeout` (natif) |
| `createRoom(int $max = 8, array $meta = [], bool $public = false)` | crée un salon (code à 4 lettres) ; `$meta` est envoyé à ceux qui le rejoignent ; `$public` : `quickJoin()` peut y amener des inconnus |
| `quickJoin(int $max = 8, array $meta = [])` | salon public : rejoint celui qui a le même `$meta`, une place libre et le plus de joueurs, sinon en crée un |
| `lockRoom(bool $locked)` | l'hôte : plus personne n'arrive par `quickJoin()` (une partie est en cours) ; le code marche toujours |
| `joinRoom(string $code)` / `leaveRoom()` | |
| `send(string $data, ?int $to = null, bool $reliable = true)` | 64 Ko au plus par message |
| `poll(): Event[]` | à appeler à chaque frame |
| `state()` / `close()` | `CONNECTING`, `OPEN`, `CLOSING`, `CLOSED` |

Salons : les pairs sont numérotés 0, 1, 2… Le créateur est le n° 0 et l'**hôte**. Quand l'hôte part, le plus
petit numéro restant le devient (`Event::HOST`).

## Dans php-wasm

1. Le moteur est compilé avec l'extension (voir le `Dockerfile` de php-wasm-sdl) :
   - `ext/wasmnet` copié dans `php-src/ext/wasmnet`, puis `--enable-wasmnet` à la configuration ;
   - `--js-library js/library_wasmnet.js` à l'édition de liens.
2. La page charge `js/php-wasm-network.js` **avant** le moteur.
3. Optionnel, pour WebRTC derrière un NAT strict :
   `PhpWasmNetwork.defaults.iceServers = [{urls: 'stun:…'}, {urls: 'turn:…', username, credential}]`.

## Serveur

```bash
php server/bin/server.php tcp://0.0.0.0:8090        # développement : ws://localhost:8090
```

En production (`wss://` est obligatoire depuis une page HTTPS), avec Caddy pour le certificat et coturn
pour TURN :

```bash
cd server && cp .env.example .env    # DOMAIN, TURN_USER, TURN_PASSWORD, TURN_REALM
docker compose up -d --build         # wss://DOMAIN/ws ; turn:DOMAIN:3478
```

- Ports à ouvrir : 80 et 443 (Caddy), 3478 en UDP et TCP, et 49160 à 49200 en UDP (TURN).
- `GET /health` renvoie le nombre de salons (dont publics) et de clients.

## Protocole

Sur un seul WebSocket (détail dans `php/src/Protocol.php`) :

- **trames texte** : contrôle en JSON (`create`, `join`, `quick`, `lock`, `leave`, `signal`, `ping` ;
  réponses `room`, `peer-join`, `peer-leave`, `host`, `signal`, `pong`, `error`) ;
- **trames binaires** : messages relayés, `[destinataire u8 | 255 = tous] données`, reçus sous la forme
  `[émetteur u8] données`.

En WebRTC, le nouvel arrivant d'un salon fait les offres. La signalisation passe par le relais. Chaque pair
a deux DataChannels : `r`, fiable et ordonné, et `u`, non fiable et non ordonné (comme UDP). Un pair
injoignable en direct en 10 s reste sur le relais.

## Tests

```bash
composer dump-autoload
php tests/native-test.php                         # serveur + clients natifs
php server/bin/server.php tcp://127.0.0.1:18555 & python3 -m http.server 18556
# puis, dans un navigateur : http://127.0.0.1:18556/tests/browser-test.html
# console : await runTests('ws://127.0.0.1:18555/ws', 'webrtc')
php examples/chat/chat.php ws://localhost:8090 [CODE]   # petit chat en ligne de commande
```

## Licence

MIT
