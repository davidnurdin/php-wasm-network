#!/usr/bin/env bash
# Met à jour php-wasm-network (git) et relance le relay : ./update.sh [ref]   (défaut : main)
set -euo pipefail
cd "$(dirname "$0")"
REF="${1:-main}"
[ -d php-wasm-network/.git ] || git clone https://github.com/davidnurdin/php-wasm-network.git php-wasm-network
git -C php-wasm-network fetch -q origin
git -C php-wasm-network checkout -q "$REF"
git -C php-wasm-network pull -q --ff-only 2>/dev/null || true
echo "php-wasm-network @ $(git -C php-wasm-network rev-parse --short HEAD)"
docker compose up -d --build
docker compose ps
