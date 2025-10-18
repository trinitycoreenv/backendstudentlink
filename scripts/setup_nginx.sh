#!/usr/bin/env bash
set -euo pipefail

DOMAIN=${DOMAIN:-bcpstudentlink.online}
NODE_PORT=${NODE_PORT:-3000}
API_PUBLIC_DIR=${API_PUBLIC_DIR:-/var/www/api/public}
PHP_FPM_SOCK=${PHP_FPM_SOCK:-/run/php/php8.3-fpm.sock}

if ! command -v nginx >/dev/null; then
  echo "nginx not installed" >&2
  exit 1
fi

CONF_PATH=/etc/nginx/sites-available/studentlink
ENABLED_PATH=/etc/nginx/sites-enabled/studentlink

read -r -d '' CONF <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};

    # Frontend: Next.js via PM2
    location / {
        proxy_pass http://127.0.0.1:${NODE_PORT};
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
    }

    # Backend: Laravel under /api
    location ^~ /api/ {
        alias ${API_PUBLIC_DIR}/;
        index index.php;
        try_files $uri $uri/ /index.php?$query_string;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:${PHP_FPM_SOCK};
            fastcgi_param SCRIPT_FILENAME $request_filename;
        }

        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
            expires 7d;
            access_log off;
        }
    }

    access_log /var/log/nginx/studentlink_access.log;
    error_log /var/log/nginx/studentlink_error.log;
}
NGINX

# Write config and enable
sudo tee "$CONF_PATH" >/dev/null <<< "$CONF"
if [[ ! -e "$ENABLED_PATH" ]]; then
  sudo ln -s "$CONF_PATH" "$ENABLED_PATH"
fi

# Test and reload
sudo nginx -t
sudo systemctl reload nginx

echo "Nginx configured for ${DOMAIN} (web on :${NODE_PORT}, API at /api)."