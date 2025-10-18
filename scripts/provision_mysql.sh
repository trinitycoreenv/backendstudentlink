#!/usr/bin/env bash
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/api}
cd "$APP_DIR"

DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d'=' -f2 | tr -d '\r')
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d'=' -f2 | tr -d '\r')
DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d'=' -f2 | tr -d '\r')

if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
  echo "Missing DB env values. Check .env (DB_DATABASE/DB_USERNAME/DB_PASSWORD)." >&2
  exit 1
fi

mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "MySQL provisioned: $DB_NAME for user $DB_USER"