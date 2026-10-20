# Déploiement derrière un Traefik existant

Le serveur du multijoueur en ligne de [Sonic PHP](https://davidnurdin.github.io/php-wasm-sdl/), tel qu'il
tourne en production (`wss://sonic.mimichat.fr/ws`) sur une machine où Traefik sert déjà d'autres sites sur
80/443. Copier ce dossier sur la machine (par exemple `/mimichatPROD/sonicGameServerPhp`), remplir `.env`.

| Service | Rôle | Accès |
|---|---|---|
| `relay` | salons, relais, signalisation WebRTC (PHP) | interne, port 8090 |
| `caddy` | TLS Let's Encrypt de `$DOMAIN` | `wss://$DOMAIN/ws`, `https://$DOMAIN/health` |
| `coturn` | STUN / TURN (identifiants temporaires donnés par le relay) | `$TURN_PORT` udp/tcp, relais `$TURN_MIN_PORT`-`$TURN_MAX_PORT`/udp |
| `site` | le site [sonicGameSite](https://github.com/davidnurdin/sonicGameSite) (Symfony, FrankenPHP, hub Mercure) | `https://$DOMAIN/`, par caddy |
| `db` | MariaDB du site | réseau `sitedb`, seul `site` y accède |

**Traefik** n'est pas modifié (réseau Docker `traefik_web`, entrypoints `insecure` :80 et `secure` :443). Les labels de `caddy` ajoutent :
- un routeur HTTP `Host($DOMAIN, $DOMAIN_ALIAS)` sur le port 80 (challenge ACME de Caddy) ;
- un routeur TCP `HostSNI($DOMAIN, $DOMAIN_ALIAS)` en *passthrough* sur le port 443 : le TLS de ce domaine est terminé par Caddy ;

Traefik passe le TLS tel quel : Caddy ne voit que l'adresse de Traefik. L'adresse réelle des visiteurs vient
donc du proxy Cloudflare devant `$DOMAIN` (`CF-Connecting-IP`), et Caddy la passe au site dans
`X-Forwarded-For`. Cloudflare ne passe que le web : le TURN utilise `$TURN_HOST`, un nom qui mène
directement à la machine (sans proxy). Le Caddyfile marche avec les modes SSL « Flexible » (Cloudflare
arrive en http) et « Full ».

`$DOMAIN_ALIAS` garde le relais (`/ws`, `/health`) pour les anciens jeux ; ses pages redirigent vers `$DOMAIN`.

Les autres domaines ne changent pas.

Ce coturn utilise 3480 et 49300-49340 (un autre coturn sur la machine peut garder 3478 et 49160-49200). Il refuse de relayer vers les adresses privées.

```bash
cp .env.example .env
for v in TURN_SECRET SITE_APP_SECRET SITE_MERCURE_SECRET SITE_DB_PASSWORD SITE_DB_ROOT_PASSWORD; do
  sed -i "s/^$v=.*/$v=$(openssl rand -hex 32)/" .env
done
./update-site.sh            # clone / met à jour sonicGameSite (le site)
./update.sh                 # clone / met à jour php-wasm-network, build, up -d
docker compose logs -f relay
curl https://$DOMAIN/health
```
