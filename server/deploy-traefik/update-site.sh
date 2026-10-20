#!/usr/bin/env bash
# Met à jour le site (sonicGameSite, git) et le relance : ./update-site.sh [ref]   (défaut : main)
# Les migrations de la base sont jouées au démarrage du conteneur (docker/entrypoint.sh du site).
set -euo pipefail
cd "$(dirname "$0")"
REF="${1:-main}"
[ -d sonicGameSite/.git ] || git clone https://github.com/davidnurdin/sonicGameSite.git sonicGameSite
git -C sonicGameSite fetch -q origin
git -C sonicGameSite checkout -q "$REF"
git -C sonicGameSite pull -q --ff-only 2>/dev/null || true
echo "sonicGameSite @ $(git -C sonicGameSite rev-parse --short HEAD)"
docker compose up -d --build site
docker compose ps site db
