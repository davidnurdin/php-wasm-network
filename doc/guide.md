# Guide de php-wasm-network

Ce guide explique comment fonctionne le réseau de php-wasm-network, des deux côtés, et comment s'en servir
depuis PHP. Pas besoin de connaître WebSocket ou WebRTC pour le lire : les notions sont expliquées au
passage.

Sommaire :

1. [Le problème à résoudre](#1-le-problème-à-résoudre)
2. [Vue d'ensemble](#2-vue-densemble)
3. [Les notions : WebSocket, WebRTC, STUN, TURN](#3-les-notions--websocket-webrtc-stun-turn)
4. [Côté client : utiliser le réseau depuis PHP](#4-côté-client--utiliser-le-réseau-depuis-php)
5. [Côté client : ce qui se passe sous le capot](#5-côté-client--ce-qui-se-passe-sous-le-capot)
6. [Côté serveur : le relais](#6-côté-serveur--le-relais)
7. [Déployer en production](#7-déployer-en-production)
8. [Le protocole, message par message](#8-le-protocole-message-par-message)
9. [Concevoir les messages de son application](#9-concevoir-les-messages-de-son-application)
10. [Exemple réel : le multijoueur de Sonic PHP](#10-exemple-réel--le-multijoueur-de-sonic-php)
11. [Tester et dépanner](#11-tester-et-dépanner)

---

## 1. Le problème à résoudre

[php-wasm](https://github.com/davidnurdin/php-wasm-sdl) fait tourner PHP dans le navigateur. Pour un jeu
en ligne, deux joueurs doivent pouvoir s'échanger des messages. Or :

- **un navigateur n'ouvre pas de socket** comme `stream_socket_client()`. Il ne connaît que HTTP, les
  **WebSocket** (une connexion permanente vers un serveur) et **WebRTC** (une connexion directe entre deux
  navigateurs) ;
- **PHP en wasm ne doit jamais bloquer**. Sa boucle de jeu tourne une fois par image
  (`requestAnimationFrame`, 60 fois par seconde). Un `fread()` qui attend gèlerait la page entière ;
- **deux navigateurs ne se trouvent pas tout seuls**. Il faut un serveur qui les présente l'un à l'autre.

php-wasm-network règle les trois avec trois briques :

- une **API PHP** unique (`PhpWasmNetwork\Connection`), qui ne bloque jamais : on la *sonde* à chaque
  image (`poll()`) ;
- dans le navigateur, un **pont JavaScript** (`php-wasm-network.js`) qui tient les vraies connexions et
  met les messages en file d'attente pour PHP ;
- un **serveur relais** en PHP pur (`server/bin/server.php`). Il gère les salons, relaie les messages et
  sert d'intermédiaire pour établir WebRTC (la « signalisation »).

La même API marche aussi en **PHP natif**, en ligne de commande ou dans une application desktop. Le même
code de jeu tourne donc dans les deux mondes.

## 2. Vue d'ensemble

```
        Joueur A (navigateur)                                           Joueur B (navigateur)
 ┌──────────────────────────────┐                               ┌──────────────────────────────┐
 │ code PHP du jeu              │                               │ code PHP du jeu              │
 │   Network::connect()         │                               │   Network::connect()         │
 │   ->poll() à chaque image    │                               │   ->poll() à chaque image    │
 │        │                     │                               │        │                     │
 │ WasmConnection (PHP)         │                               │ WasmConnection (PHP)         │
 │        │  fonctions wasmnet_*│                               │        │                     │
 │ extension C « wasmnet »      │                               │ extension C « wasmnet »      │
 │        │                     │                               │        │                     │
 │ php-wasm-network.js (JS) ────┼──── WebRTC, direct ───────────┼── php-wasm-network.js (JS)   │
 └────────┼─────────────────────┘    (si possible)              └────────┼─────────────────────┘
          │ WebSocket (wss://)                                           │ WebSocket (wss://)
          ▼                                                              ▼
   ┌────────────────────────────────────────────────────────────────────────────┐
   │ serveur relais (PHP) : salons · relais des messages · signalisation WebRTC  │
   └────────────────────────────────────────────────────────────────────────────┘
          ▲
          │ WebSocket
 ┌────────┴─────────────────────┐
 │ Joueur C en PHP natif        │   (pas de WebRTC en PHP natif : tout passe par le relais)
 │   NativeConnection           │
 └──────────────────────────────┘
```

Chaque client a **toujours** un WebSocket vers le serveur. Il sert à entrer dans un salon, à recevoir
les arrivées et départs, et à relayer les messages. Avec le transport `webrtc`, les navigateurs ouvrent
**en plus** une liaison directe entre eux. Une fois ouverte, les messages passent par là, plus vite et sans
charger le serveur. Si elle n'arrive pas à s'ouvrir, les messages continuent par le relais, sans que
l'application ait rien à faire.

## 3. Les notions : WebSocket, WebRTC, STUN, TURN

### WebSocket

Une connexion **permanente et bidirectionnelle** entre un navigateur et un serveur. Elle commence comme
une requête HTTP (`GET /ws` avec `Upgrade: websocket`), puis le serveur répond `101 Switching Protocols`.
La même connexion TCP transporte ensuite des **trames** dans les deux sens :

- des trames **texte** : ici, du JSON de contrôle (« crée un salon », « je veux rejoindre ABCD ») ;
- des trames **binaires** : ici, les messages de l'application, relayés tels quels.

Depuis une page en HTTPS, le navigateur exige `wss://` (WebSocket chiffré par TLS). C'est pour cela qu'un
proxy TLS (Caddy) se place devant le relais en production.

C'est fiable et ordonné (TCP), mais chaque message fait un aller-retour par le serveur.

### WebRTC et les DataChannels

WebRTC permet à deux navigateurs de se parler **directement**, sans serveur au milieu une fois la liaison
établie. On l'utilise sans audio ni vidéo, seulement avec des **DataChannels**, des canaux de données.
php-wasm-network en ouvre deux par paire de joueurs :

| Canal | Réglages | Comportement | Pour quoi |
|---|---|---|---|
| `r` | `ordered: true` | fiable et ordonné, comme TCP | événements importants : « touché », « fin de partie », discussion |
| `u` | `ordered: false, maxRetransmits: 0` | **non fiable** : un message perdu n'est pas renvoyé, comme UDP | état envoyé 20 à 30 fois par seconde : le suivant remplace le précédent |

Pourquoi un canal non fiable ? Sur un canal fiable, un seul paquet perdu bloque tous les suivants jusqu'à
sa réémission. Pour une position qui change 30 fois par seconde, mieux vaut perdre l'ancienne que la
recevoir en retard.

### La signalisation

Pour s'ouvrir, une liaison WebRTC demande que les deux navigateurs échangent d'abord quelques messages :

1. une **offre** (SDP) : « voilà ce que je sais faire » ;
2. une **réponse** (SDP) : « d'accord, voilà ce que je fais » ;
3. des **candidats ICE** : « tu peux essayer de me joindre à telle adresse IP et tel port ».

Ils ne peuvent pas se les envoyer directement, puisque c'est justement ce qu'ils cherchent à faire. Ces
messages passent donc par le serveur relais (message de contrôle `signal`). C'est la *signalisation*.

### NAT, STUN et TURN

La plupart des joueurs sont derrière une box (un **NAT**). Leur machine a une adresse privée
(`192.168.x.x`) que personne ne peut joindre de l'extérieur.

- **STUN** : un petit serveur qui répond « vu d'Internet, tu as l'adresse 203.0.113.7:51234 ». Le
  navigateur donne cette adresse publique comme candidat (type `srflx`). Avec la plupart des box, cela
  suffit à ouvrir la liaison directe.
- **TURN** : avec certains NAT stricts (réseaux d'entreprise, certains opérateurs mobiles), même cela
  échoue. Un serveur TURN sert alors de **relais de trafic** : chaque navigateur lui envoie ses paquets et
  il les fait suivre (candidat de type `relay`). Ça reste du WebRTC, avec un détour.

Les trois sortes de candidats, du meilleur au moins bon : `host` (même réseau local), `srflx` (direct à
travers les NAT grâce à STUN), `relay` (via TURN).

Un serveur TURN ouvert à tous serait un relais gratuit pour n'importe qui. On le protège donc par des
**identifiants temporaires**. Le serveur relais connaît un secret partagé avec coturn, et fabrique pour
chaque client un identifiant valable quelques heures (voir [6.4](#64-les-identifiants-turn)). Le secret ne
quitte jamais le serveur.

### Et si rien ne marche ?

Si la liaison directe ne s'ouvre pas en **10 secondes**, php-wasm-network abandonne pour ce pair et ses
messages passent par le WebSocket du relais. L'application en est prévenue par un événement
`PEER_TRANSPORT`, mais elle n'a rien à faire : `send()` choisit le chemin tout seul.

## 4. Côté client : utiliser le réseau depuis PHP

### 4.1 Installation

```bash
composer require davidnurdin/php-wasm-network:dev-main
# (dépôt VCS : "repositories": [{"type": "vcs", "url": "https://github.com/davidnurdin/php-wasm-network"}])
```

Dans le navigateur, il faut en plus un moteur php-wasm compilé avec l'extension `wasmnet`, et le script
`js/php-wasm-network.js` chargé dans la page **avant** le moteur (voir [5.3](#53-intégrer-dans-un-moteur-php-wasm)).

### 4.2 Se connecter

```php
use PhpWasmNetwork\Network;

$net = Network::connect('wss://sonic.mimichat.fr/ws', ['transport' => 'webrtc']);
```

`Network::connect()` choisit tout seul la bonne implémentation :

- dans le navigateur (l'extension `wasmnet` est là) : `WasmConnection`, en WebSocket et WebRTC ;
- en PHP natif : `NativeConnection`, un client WebSocket écrit en PHP. Il passe toujours par le relais,
  car il n'y a pas de WebRTC en PHP.

Options :

| Option | Valeurs | Défaut |
|---|---|---|
| `transport` | `'ws'` : tout par le relais ; `'webrtc'` : direct quand c'est possible, relais sinon | `'ws'` |
| `iceServers` | serveurs STUN / TURN pour WebRTC (`[['urls' => 'stun:…'], …]`) | un STUN public, ou ceux que donne le serveur |
| `iceTransportPolicy` | `'relay'` : uniquement via TURN (tests, ou pour cacher les adresses IP des joueurs) | `'all'` |
| `timeout` | natif seulement : secondes pour la connexion TCP + TLS | `5.0` |

Le constructeur ne bloque pas. La connexion se fait en arrière-plan, et on apprend qu'elle est prête par
l'événement `OPEN`.

### 4.3 La boucle : `poll()` à chaque image

Tout ce qui arrive est un **événement** (`PhpWasmNetwork\Event`) : `$type`, `$from` (le numéro du pair,
-1 pour le serveur) et `$data` (une chaîne d'octets). On les lit une fois par image :

```php
use PhpWasmNetwork\Event;

foreach ($net->poll() as $event) {
    switch ($event->type) {
        case Event::OPEN:          // connecté au serveur : on peut entrer dans un salon
            $net->createRoom(4, ['game' => 'mon-jeu', 'v' => 1]);
            break;
        case Event::ROOM:          // dans un salon
            $info = $event->info();    // ['room' => 'KRBR', 'peer' => 0, 'host' => 0, 'peers' => [], 'meta' => …, 'public' => false]
            break;
        case Event::PEER_JOIN:     // $event->from vient d'arriver
        case Event::PEER_LEAVE:    // $event->from est parti
            break;
        case Event::HOST:          // l'hôte est parti : $event->from est le nouvel hôte
            break;
        case Event::MESSAGE:       // $event->data envoyé par $event->from
            break;
        case Event::PEER_TRANSPORT:// le chemin vers $event->from : 'webrtc' (direct) ou 'relay'
            break;
        case Event::PONG:          // réponse à ping() : $event->info()['ms']
            break;
        case Event::ERROR:         // $event->info()['code'] : no-room, room-full, connection, not-open...
            break;
        case Event::CLOSE:         // connexion fermée ($event->data : la raison)
            break;
    }
}
```

Dans le navigateur, on appelle `poll()` dans la fonction d'image du jeu. En natif, on peut tourner en
boucle avec un `usleep()`, comme l'exemple `examples/chat/chat.php`.

### 4.4 Les salons

Un **salon** regroupe jusqu'à 16 pairs (`Protocol::MAX_PEERS`). Chaque pair y a un **numéro** : 0, 1, 2…
Le créateur est le numéro 0 et l'**hôte**. Si l'hôte part, le plus petit numéro restant devient hôte
(événement `HOST`). Le serveur ne donne aucun pouvoir particulier à l'hôte. C'est l'application qui décide
de son rôle (dans Sonic, c'est lui qui fait autorité sur la partie).

| Méthode | Effet |
|---|---|
| `createRoom(int $max = 8, array $meta = [], bool $public = false)` | crée un salon avec un code de 4 lettres (sans I ni O). `$meta` est envoyé à tous ceux qui entrent (nom du jeu, version, mode…) |
| `joinRoom(string $code)` | entre dans le salon `$code`. Erreurs possibles : `no-room`, `room-full` |
| `quickJoin(int $max = 8, array $meta = [])` | **salon public** : entre dans le salon public qui a **le même `$meta`**, une place libre et le plus de joueurs, ou en crée un |
| `lockRoom(bool $locked)` | l'hôte seulement : plus personne n'arrive par `quickJoin()`. Le code marche toujours |
| `setRoomInfo(array $info)` | l'hôte seulement : ce que la liste des salons publics montre de ce salon (`GET /health?rooms`), 2 Ko au plus |
| `leaveRoom()` | quitte le salon (la connexion reste ouverte) |

#### Les spectateurs

Un **spectateur** suit un salon sans y jouer : c'est par exemple une page web à côté du jeu, qui montre qui
est là et la discussion. Il entre avec `watch {room}`, ou crée le salon avec `create {…, watch: true}`
(`NativeConnection::watchRoom()` côté PHP natif ; une page web parle directement le protocole, voir la
partie 8).

- Les spectateurs ont des numéros à partir de **128** (`Protocol::FIRST_WATCHER`). Ils ne sont jamais
  hôtes et ne comptent pas dans `max`.
- Ils ne reçoivent pas ce qu'un pair envoie à tous (255) : en WebRTC, ces messages vont souvent de pair à
  pair sans passer par le serveur. L'application leur envoie ce qui les concerne à l'adresse **254**
  (`Protocol::WATCHERS`, tous les spectateurs), ou à l'un d'eux par son numéro.
- Ce qu'un spectateur envoie à tous (255) arrive aux pairs **et** aux autres spectateurs.
- Un salon sans pair a l'hôte `-1`. Le premier pair qui y entre devient l'hôte.
- Un salon vide est gardé **90 secondes** : la page qui l'a créé peut ouvrir le jeu, qui le rejoint.
- Les pairs ne voient arriver un spectateur que par `watcher-join` (ignoré par les bibliothèques). Son
  départ arrive comme celui d'un pair (`PEER_LEAVE`, numéro ≥ 128).

Conseil : mettez **une version** dans `$meta` (`['game' => 'sonic', 'v' => 3]`). `quickJoin()` ne réunit
que des `$meta` identiques, donc deux versions incompatibles ne se retrouvent jamais ensemble. Avec un
code, vérifiez vous-même la version reçue dans l'événement `ROOM`.

### 4.5 Envoyer

```php
$net->send($data);                       // à tous les autres, fiable
$net->send($data, to: 2);                // au pair n° 2 seulement
$net->send($data, null, reliable: false);// à tous, non fiable (canal 'u' en WebRTC)
```

- `$data` est une chaîne d'octets PHP : du JSON, du texte, ou du binaire fabriqué avec `pack()`.
- Taille maximale : 64 Kio par message (`Protocol::MAX_MESSAGE`).
- `reliable: false` n'a d'effet qu'en WebRTC direct. Par le relais (WebSocket, donc TCP), tout est fiable.
  N'utilisez donc jamais le non fiable pour quelque chose qui *doit* arriver.
- On ne reçoit jamais ses propres messages.
- `send()` renvoie `false` si la connexion n'est pas ouverte ou si le message est trop gros.

### 4.6 Mesurer et fermer

- `ping()` : demande un aller-retour au serveur. La réponse arrive en `PONG` avec `['ms' => 41.3]`.
  Dans le navigateur, une connexion envoie aussi d'elle-même un petit ping toutes les 15 s : le serveur
  ferme les clients muets depuis 60 s.
- `state()` : `Connection::CONNECTING`, `OPEN`, `CLOSING` ou `CLOSED`.
- `close()` : ferme tout, y compris les liaisons WebRTC.
- Depuis JavaScript : `await PhpWasmNetwork.stats(handle)` renvoie, pour chaque pair direct, le type de
  chemin (`host`, `srflx`, `relay`) et le temps d'aller-retour en millisecondes.

### 4.7 Un exemple complet : un mini-chat

```php
<?php
require 'vendor/autoload.php';

use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

$net = Network::connect($argv[1] ?? 'ws://localhost:8090/ws');
$code = $argv[2] ?? null;                         // pas de code : on crée un salon
stream_set_blocking(STDIN, false);

while ($net->state() !== $net::CLOSED) {
    foreach ($net->poll() as $event) {
        match ($event->type) {
            Event::OPEN => $code ? $net->joinRoom($code) : $net->createRoom(8, ['app' => 'chat']),
            Event::ROOM => print("salon {$event->info()['room']}, je suis #{$event->info()['peer']}\n"),
            Event::MESSAGE => print("#{$event->from} : {$event->data}\n"),
            Event::PEER_JOIN => print("#{$event->from} est arrivé\n"),
            Event::PEER_LEAVE => print("#{$event->from} est parti\n"),
            Event::ERROR => print("erreur : {$event->info()['code']}\n"),
            default => null,
        };
    }
    if (($line = fgets(STDIN)) !== false && trim($line) !== '') $net->send(trim($line));
    usleep(16000);   // ~60 fois par seconde, comme une boucle de jeu
}
```

C'est `examples/chat/chat.php`, en un peu plus court. Lancez le serveur, puis le chat dans deux terminaux :
le premier affiche un code, le second le reçoit en argument.

## 5. Côté client : ce qui se passe sous le capot

### 5.1 Du PHP au JavaScript

```
 PHP  WasmConnection::send($data, $to, $reliable)
        └─ wasmnet_send($handle, $data, $to, $reliable)        ← fonction de l'extension C (ext/wasmnet)
             └─ wasmnet_js_send(...)                           ← déclarée en C, écrite en JS (library_wasmnet.js)
                  └─ PhpWasmNetwork.send(handle, Uint8Array, to, reliable)   ← php-wasm-network.js
                       ├─ DataChannel 'r' ou 'u' du pair      (WebRTC ouvert)
                       └─ WebSocket : [to] + données          (sinon : par le relais)
```

L'extension C ne fait que copier des octets entre la mémoire de PHP (le tas wasm, `HEAPU8`) et le
JavaScript. Elle expose six fonctions :

| Fonction | Rôle |
|---|---|
| `wasmnet_connect(string $url, string $optionsJson): int\|false` | ouvre une connexion, renvoie son numéro |
| `wasmnet_control(int $h, string $json): bool` | envoie un message de contrôle JSON au serveur |
| `wasmnet_send(int $h, string $data, int $to, bool $reliable): bool` | envoie des octets |
| `wasmnet_poll(int $h): ?array` | le prochain événement `[type, from, data]`, ou `null` |
| `wasmnet_state(int $h): int` | l'état de la connexion |
| `wasmnet_close(int $h): void` | ferme |

On ne les appelle pas directement : `WasmConnection` les enveloppe.

### 5.2 La file d'attente

Le navigateur reçoit les messages quand il veut (callbacks `onmessage`), pendant que PHP fait autre chose.
Le JavaScript les range donc dans une **file par connexion**, et `wasmnet_poll()` les retire un par un.
C'est ce qui rend l'API non bloquante. Si personne ne lit, la file est plafonnée à 10 000 événements.

### 5.3 Intégrer dans un moteur php-wasm

1. Compiler PHP avec l'extension (c'est ce que fait le `Dockerfile` de php-wasm-sdl, commit épinglé par
   `NETWORK_REF`) :
   - copier `ext/wasmnet` dans `php-src/ext/wasmnet` et configurer avec `--enable-wasmnet` ;
   - à l'édition de liens emscripten : `--js-library js/library_wasmnet.js`.
2. Charger le pont dans la page, **avant** le moteur :
   ```html
   <script src="php-wasm-network.js"></script>
   <script type="module" src="php-web.mjs"></script>
   ```
3. Optionnel : imposer ses serveurs STUN / TURN à toutes les connexions :
   ```js
   PhpWasmNetwork.defaults.iceServers = [{ urls: 'stun:stun.example.org:3478' }];
   ```
   Sans cela, chaque connexion utilise ceux que le serveur relais lui donne à la connexion (message
   `hello`), ou à défaut un STUN public de Google.

Quand seul `php-wasm-network.js` change, il n'y a pas besoin de recompiler le moteur : le fichier est
servi à côté. Il faut recompiler seulement si `ext/wasmnet` ou `library_wasmnet.js` changent.

### 5.4 L'ouverture d'une liaison WebRTC, pas à pas

Prenons A déjà dans le salon, et B qui arrive (B a le numéro 1) :

```
 B → serveur : join ABCD
 serveur → B : room (peers: [0])           serveur → A : peer-join 1
 B : nouvel arrivant, c'est lui qui fait l'offre vers chaque pair déjà là
 B : crée l'RTCPeerConnection, les canaux 'r' et 'u', puis l'offre SDP
 B → serveur → A : signal {sdp: offre}
 A : crée sa RTCPeerConnection, applique l'offre, fabrique la réponse
 A → serveur → B : signal {sdp: réponse}
 A ⇄ serveur ⇄ B : signal {candidate: …}    (plusieurs, au fil de la découverte ICE)
 … les navigateurs testent les chemins (host, srflx, relay) et gardent le meilleur …
 canaux ouverts → événement PEER_TRANSPORT 'webrtc' des deux côtés
```

Pendant toute cette phase (quelques dizaines de millisecondes en général), les `send()` passent déjà par
le relais. Rien n'est perdu.

### 5.5 Le client PHP natif

`NativeConnection` est un client WebSocket complet en PHP (`stream_socket_client`, en `ssl://` pour
`wss://`) en mode **non bloquant** :

- `poll()` lit ce qui est arrivé sans attendre, décode les trames (`WebSocket\Frames`) et renvoie les
  mêmes événements que dans le navigateur ;
- il envoie un ping toutes les 15 s pour que le serveur ne le ferme pas ;
- il ignore `signal` (pas de WebRTC). Les navigateurs du salon renoncent au direct avec lui au bout de
  10 s et passent par le relais.

On peut donc écrire un bot, un outil d'administration ou un serveur de jeu en PHP natif qui rejoint les
mêmes salons que les navigateurs.

## 6. Côté serveur : le relais

### 6.1 Ce qu'il fait (et ne fait pas)

`PhpWasmNetwork\Server\RelayServer` est un seul processus PHP, une seule boucle `stream_select()`, sans
aucune dépendance. Il :

- accepte les connexions WebSocket (et répond à `GET /health` en JSON) ;
- gère les **salons** : création, codes, places, hôte, salons publics et verrouillage ;
- **relaie** les trames binaires d'un pair vers un autre ou vers tous ;
- fait passer la **signalisation** WebRTC entre deux pairs du même salon ;
- donne à chaque client ses serveurs STUN / TURN et des identifiants TURN temporaires.

Il **ne lit jamais** le contenu des messages relayés, et ne connaît aucune règle du jeu.

### 6.2 Lancer

```bash
php server/bin/server.php                          # écoute tcp://0.0.0.0:8090 → ws://localhost:8090/ws
php server/bin/server.php tcp://127.0.0.1:18090    # autre adresse
PHP_WASM_NETWORK_LISTEN=tcp://0.0.0.0:9000 php server/bin/server.php
```

Variables d'environnement lues par `server.php` :

| Variable | Rôle |
|---|---|
| `PHP_WASM_NETWORK_LISTEN` | adresse d'écoute (si pas d'argument) |
| `TURN_SECRET` | secret partagé avec coturn (`--static-auth-secret`). Sans lui, pas d'identifiants TURN |
| `TURN_URLS` | adresses TURN, séparées par des virgules (`turn:hote:3478?transport=udp,turn:hote:3478?transport=tcp`) |
| `STUN_URLS` | adresses STUN (`stun:hote:3478`) |
| `TURN_TTL` | durée de vie des identifiants, en secondes (6 h par défaut) |

Il s'arrête proprement sur `SIGTERM` / `SIGINT` (extension `pcntl`).

### 6.3 Les limites et protections

| Limite | Valeur |
|---|---|
| clients simultanés | 2 000 (`$maxClients`) |
| client silencieux fermé après | 60 s (10 s avant la fin de la poignée de main) |
| pairs par salon | 16 |
| taille d'un message | 64 Kio |
| tampon de sortie d'un client trop lent | 4 Mio, puis il est déconnecté |
| `meta` d'un salon | 4 Kio de JSON |

Une erreur sur une connexion (message mal formé, bug) ferme cette connexion seulement, jamais le serveur
et les autres salons. Les départs en cascade (plusieurs clients qui ferment en même temps) sont gérés : le
nouvel hôte est choisi avant de prévenir les autres.

### 6.4 Les identifiants TURN

Le mécanisme est la « TURN REST API » de coturn (`--use-auth-secret`) :

```
username   = "<date d'expiration unix>:php-wasm-network-<aléatoire>"
credential = base64( HMAC-SHA1( TURN_SECRET, username ) )
```

coturn connaît le même secret. Il recalcule le HMAC et vérifie que la date n'est pas passée. Le serveur
relais envoie ces identifiants dans le message `hello`, à chaque connexion. Le secret ne sort jamais du
serveur, et un identifiant volé dans une page ne sert que quelques heures. Chaque client reçoit un nom
différent, parce que coturn limite le nombre d'allocations par nom (`--user-quota`).

### 6.5 L'utiliser dans son propre code

```php
use PhpWasmNetwork\Server\RelayServer;
use PhpWasmNetwork\Server\TurnCredentials;

$turn = TurnCredentials::fromEnv();
$server = new RelayServer('tcp://0.0.0.0:8090', fn(string $line) => error_log($line),
    iceServers: $turn ? fn() => $turn->iceServers() : null);
$server->run();            // boucle jusqu'à stop()
// ou, dans votre propre boucle : $server->tick(0.05);
```

## 7. Déployer en production

Depuis une page en HTTPS (GitHub Pages, par exemple), le navigateur exige `wss://`. Il faut donc un
certificat TLS devant le relais. Deux recettes sont fournies dans `server/` :

### 7.1 Machine dédiée (ports 80 et 443 libres) : `server/docker-compose.yml`

```bash
cd server
cp .env.example .env
sed -i "s/^TURN_SECRET=.*/TURN_SECRET=$(openssl rand -hex 32)/" .env
# éditer DOMAIN et PUBLIC_IP
docker compose up -d --build
curl https://$DOMAIN/health        # {"ok":true,"rooms":0,"public":0,"clients":0,"players":0,"watchers":0}
curl 'https://$DOMAIN/health?rooms' # en plus : "list", les salons publics (code, joueurs, spectateurs, info)
```

- `relay` : le serveur PHP (image `php:8.3-cli-alpine`, utilisateur `nobody`) ;
- `caddy` : obtient et renouvelle le certificat Let's Encrypt, envoie `/ws` et `/health` au relais ;
- `coturn` : STUN / TURN, en `network_mode: host` (il a besoin de beaucoup de ports UDP). Il refuse de
  relayer vers les adresses privées : un client TURN ne doit pas pouvoir atteindre les services internes
  de la machine.

Ports à ouvrir : 80, 443, `TURN_PORT` en UDP et TCP, et la plage `TURN_MIN_PORT`-`TURN_MAX_PORT` en UDP.

### 7.2 Derrière un Traefik existant : `server/deploy-traefik/`

C'est la configuration de production de Sonic PHP (`wss://sonic.mimichat.fr/ws`), sur une machine où
Traefik sert déjà d'autres sites sur 80/443. Traefik n'est pas modifié : seuls des **labels** sur le
conteneur Caddy lui ajoutent deux routes :

- un routeur **HTTP** `Host(domaine)` sur le port 80, pour le challenge Let's Encrypt de Caddy ;
- un routeur **TCP** `HostSNI(domaine)` en `tls.passthrough` sur le port 443. Traefik lit seulement le
  nom demandé dans la poignée de main TLS et passe la connexion chiffrée telle quelle à Caddy, qui détient
  le certificat.

Mise à jour du serveur : `./update.sh [ref]`. Le script récupère php-wasm-network, reconstruit le seul
conteneur `relay` et le relance. Les salons en cours sont perdus, les joueurs se reconnectent.

### 7.3 Machine derrière un NAT

Si la machine n'a qu'une adresse privée (c'est le cas de beaucoup de VPS « NATés »), coturn doit savoir
les deux : `--external-ip=PUBLIQUE/PRIVÉE`, `--listening-ip` et `--relay-ip` sur l'adresse privée. C'est
ce que fait `deploy-traefik` avec `PUBLIC_IP` et `PRIVATE_IP`.

## 8. Le protocole, message par message

Un seul WebSocket par client. Référence : `php/src/Protocol.php`.

### Trames texte (contrôle, JSON)

Du client vers le serveur :

| Message | Champs | Effet |
|---|---|---|
| `create` | `max`, `meta`, `public`, `watch` | crée un salon et y entre (en spectateur avec `watch`) |
| `join` | `room` | entre dans un salon par son code |
| `watch` | `room` | entre dans un salon en spectateur |
| `info` | `data` | l'hôte (ou un spectateur s'il n'y a aucun pair) : ce que la liste des salons montre |
| `quick` | `max`, `meta` | salon public (voir `quickJoin()`) |
| `lock` | `locked` | l'hôte ferme ou rouvre son salon public |
| `leave` | | quitte le salon |
| `signal` | `to`, `data` | signalisation WebRTC vers un pair du salon |
| `ping` | `id` | demande un `pong` |

Du serveur vers le client :

| Message | Champs |
|---|---|
| `hello` | `v` (version du protocole), `ice` (serveurs STUN / TURN avec identifiants) |
| `room` | `room`, `peer` (mon numéro), `host` (-1 : aucun pair), `peers` (les autres pairs), `watchers`, `watcher` (suis-je spectateur), `meta`, `max`, `public` |
| `peer-join` / `watcher-join` / `peer-leave` | `peer` (`peer-leave` : un pair ou un spectateur) |
| `host` | `peer` (le nouvel hôte, -1 s'il ne reste aucun pair) |
| `signal` | `from`, `data` |
| `pong` | `id` |
| `error` | `code` (`no-room`, `room-full`, `bad-message`…), `message` |

### Trames binaires (messages relayés)

```
client → serveur :  [destinataire : 1 octet, 255 = tous les autres, 254 = les spectateurs] [données…]
serveur → client :  [émetteur : 1 octet]                            [données…]
```

Le serveur remplace le premier octet et fait suivre. Les liaisons WebRTC transportent les données seules
(le pair est connu par le canal).

## 9. Concevoir les messages de son application

php-wasm-network transporte des octets. Ce que ces octets veulent dire, c'est l'application qui le décide.
Quelques recettes qui ont fait leurs preuves dans Sonic PHP :

- **Un octet de type en tête** de chaque message (`chr(TYPE) . $payload`), puis un `match` à la réception.
- **JSON pour ce qui est rare** (entrée dans le salon, événements, discussion) : lisible et facile à
  faire évoluer.
- **`pack()` / `unpack()` pour ce qui est fréquent** (positions 30 fois par seconde) : 26 octets au lieu de
  200 en JSON. Par exemple `pack('ggggvvCCCCv', $x, $y, $vx, $vy, …)`, où `g` est un float little-endian et
  `v` un entier 16 bits. Vérifiez toujours la longueur reçue avant `unpack()`.
- **Fiable ou non** : l'état qui se répète → non fiable ; ce qui ne doit arriver qu'une fois et
  sûrement (un coup, un objet ramassé, la fin) → fiable.
- **Un seul qui décide** : sans arbitre, deux machines finissent toujours par se contredire. Choisissez qui
  fait autorité (l'hôte, ou un serveur de jeu) et faites passer les décisions par lui.
- **Ne croyez pas le client sur parole** : l'autorité vérifie ce qu'on lui annonce (voir 10).
- **Une version dans `meta`** pour ne pas mélanger deux formats de messages.

## 10. Exemple réel : le multijoueur de Sonic PHP

Le jeu ([sonicGamePhp](https://github.com/davidnurdin/sonicGamePhp)) est le premier utilisateur de la
bibliothèque. En résumé (le détail est dans [`doc/multijoueur-en-ligne.md`](https://github.com/davidnurdin/php-wasm-sdl/blob/main/doc/multijoueur-en-ligne.md) de php-wasm-sdl) :

- `src/Multiplayer/Online.php` : la session. Connexion (`transport: 'webrtc'` dans le navigateur, `'ws'`
  en natif : la version desktop, `./start.sh`, joue avec les navigateurs par le relais), salon privé (code)
  ou public (`quickJoin`), noms, discussion, ping, lancement de la partie.
- `src/Multiplayer/VersusNetwork.php` : la partie. L'**hôte fait autorité** :
  - chaque joueur simule son propre Sonic et l'envoie à l'hôte 30 fois par seconde (`STATE`, 26 octets,
    non fiable) ;
  - l'hôte applique les règles et renvoie l'état complet 20 fois par seconde (`SNAPSHOT`, non fiable) ;
  - les décisions passent en `EVENT` (JSON fiable) : « tu es touché », « objet détruit », « fin »… ;
  - les autres Sonic sont affichés avec 120 ms de retard, entre deux positions reçues (interpolation) ;
  - l'hôte vérifie ce que chaque joueur annonce (vitesse, anneaux, bonus) et compense la latence pour
    les contacts.

## 11. Tester et dépanner

### Tests fournis

```bash
composer dump-autoload
php tests/native-test.php        # lance un serveur et une dizaine de clients natifs (salons, relais, hôte, salons publics)
php server/bin/server.php tcp://127.0.0.1:18555 & python3 -m http.server 18556
# navigateur : http://127.0.0.1:18556/tests/browser-test.html, puis dans la console :
#   await runTests('ws://127.0.0.1:18555/ws', 'webrtc')
#   await runTests('wss://…/ws', 'webrtc', 'relay')     ← force le passage par TURN
```

### Problèmes courants

| Symptôme | Cause probable |
|---|---|
| reste en `CONNECTING`, puis rien | page HTTPS vers un `ws://` (bloqué par le navigateur), ou adresse privée bloquée par la « Private Network Access » de Chrome. Utilisez `wss://` |
| `ERROR connection` | serveur arrêté, mauvais chemin (`/ws`), certificat invalide |
| `PEER_TRANSPORT relay` au bout de 10 s | WebRTC impossible entre ces deux joueurs (NAT strict sans TURN, client natif). Ça marche quand même, par le relais |
| TURN ne marche pas | ports UDP fermés, `--external-ip` absent derrière un NAT, horloge du serveur décalée (l'expiration des identifiants) |
| `room-full` / `no-room` | salon plein, ou code faux / salon fermé (un salon disparaît quand son dernier pair part) |
| messages perdus | envoyés avec `reliable: false` : c'est voulu. Passez en fiable ce qui doit arriver |

Pour voir le chemin réellement utilisé dans le navigateur :
`await PhpWasmNetwork.stats(1)` → `{"1": {"local": "srflx", "remote": "host", "rtt": 23}}`.

Côté serveur : `docker compose logs -f relay` (créations et fermetures de salons, erreurs), et
`curl https://domaine/health`.
