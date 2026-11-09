#!/usr/bin/env bash
# Deploys the darts system. Executed by the GitHub Actions
# self-hosted runner after every push to main.
set -euo pipefail
cd /opt/darts

echo "==> Pulling latest code"
git pull --ff-only origin main

echo "==> Rebuilding & restarting containers"
docker compose up -d --build

echo "==> Pruning dangling images"
docker image prune -f

echo "==> Health check"
sleep 3
if curl -sf -o /dev/null http://192.168.1.2:8087/; then
  echo "Health check OK - darts responding on :8087"
else
  echo "Health check FAILED - darts not responding" >&2
  exit 1
fi
echo "==> Deploy complete"
