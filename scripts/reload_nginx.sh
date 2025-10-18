#!/usr/bin/env bash
set -euo pipefail

sudo nginx -t
sudo systemctl reload nginx

echo "Nginx reloaded successfully."