# Déploiement derrière un Traefik existant

Le serveur du multijoueur en ligne de [Sonic PHP](https://davidnurdin.github.io/php-wasm-sdl/), tel qu'il
tourne en production (`wss://sonic.mimichat.fr/ws`) sur une machine où Traefik sert déjà d'autres sites sur
80/443. Copier ce dossier sur la machine (par exemple `/mimichatPROD/sonicGameServerPhp`), remplir `.env`.

| Service | Rôle | Accès |
|---|---|---|
| `relay` | salons, relais, signalisation WebRTC (PHP) | interne, port 8090 |
| `caddy` | TLS Let's Encrypt de `$DOMAIN` | `wss://$DOMAIN/ws`, `https://$DOMAIN/health` |
| `coturn` | STUN / TURN (identifiants temporaires donnés par le relay) | `$TURN_PORT` udp/tcp, relais `$TURN_MIN_PORT`-`$TURN_MAX_PORT`/udp |

**Traefik** n'est pas modifié (réseau Docker `traefik_web`, entrypoints `insecure` :80 et `secure` :443). Les labels de `caddy` ajoutent :
- un routeur HTTP `Host($DOMAIN, $DOMAIN_ALIAS)` sur le port 80 (challenge ACME de Caddy) ;
- un routeur TCP `HostSNI($DOMAIN, $DOMAIN_ALIAS)` en *passthrough* sur le port 443 : le TLS de ce domaine est terminé par Caddy.

Les autres domaines ne changent pas.

Ce coturn utilise 3480 et 49300-49340 (un autre coturn sur la machine peut garder 3478 et 49160-49200). Il refuse de relayer vers les adresses privées.

```bash
cp .env.example .env && sed -i "s/^TURN_SECRET=.*/TURN_SECRET=$(openssl rand -hex 32)/" .env
./update.sh                 # clone / met à jour php-wasm-network, build, up -d
docker compose logs -f relay
curl https://$DOMAIN/health
```
