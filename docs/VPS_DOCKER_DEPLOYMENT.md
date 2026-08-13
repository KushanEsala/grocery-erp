# Grocery ERP VPS deployment

This deployment runs Grocery ERP in its own Docker Compose project while using the MySQL server already installed on the host. It does not expose MySQL publicly and does not share containers, networks, volumes, ports, databases, or database users with the existing hire-purchase deployment.

## Allocated services

| Service | Binding |
|---|---|
| Public application | `https://pos.kushanesala.me` |
| Frontend container | `127.0.0.1:3002` |
| Laravel API container | `127.0.0.1:8009` |
| phpMyAdmin (optional profile) | `127.0.0.1:8081` |
| MySQL | Existing host service on `3306` |

## Required server specifications

- Ubuntu 24.04 or newer
- 2 CPU cores minimum; 4 cores recommended
- 4 GB RAM minimum; 8 GB recommended for comfortable Docker builds
- 20 GB free SSD space minimum
- Docker Engine with Docker Compose v2
- Existing MySQL 8.x or MariaDB 10.6+ host service
- Host Nginx and Certbot
- DNS `A` record: `pos.kushanesala.me` → `162.35.172.25`

## One-time database setup

Run this in the host MySQL shell. Replace the password before running it.

```sql
CREATE DATABASE IF NOT EXISTS grocery_erp
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'grocery_erp_user'@'172.%'
  IDENTIFIED BY 'REPLACE_WITH_LONG_RANDOM_PASSWORD';

GRANT ALL PRIVILEGES ON grocery_erp.*
  TO 'grocery_erp_user'@'172.%';

FLUSH PRIVILEGES;
```

The MySQL server must listen on an address reachable through Docker's host gateway. Keep port `3306` blocked from the public internet using UFW/provider firewall rules.

## Install deployment prerequisites

```bash
apt update
apt install -y ca-certificates curl nginx certbot python3-certbot-nginx mysql-client

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo ${UBUNTU_CODENAME:-$VERSION_CODENAME}) stable" > /etc/apt/sources.list.d/docker.list
apt update
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
docker --version
docker compose version
```

## Application setup

```bash
cd /var/www/grocery-erp
git clone --branch main https://github.com/KushanEsala/grocery-erp.git .
cp .env.example .env
```

Generate an application key without installing PHP on the host:

```bash
docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Put the generated key, database password, public URLs, and a strong initial admin password in `.env`. Then start the application:

```bash
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 api nginx frontend
```

The first deployment may set `RUN_DATABASE_SEEDER=true` to create the initial company and administrator. After the first successful start, immediately change it to `false` and run `docker compose up -d`.

## Domain and HTTPS

Confirm the DNS record resolves to the VPS:

```bash
getent hosts pos.kushanesala.me
```

Install the repository's host Nginx configuration and obtain the certificate:

```bash
cp /var/www/grocery-erp/deploy/nginx/pos.kushanesala.me.conf /etc/nginx/sites-available/pos.kushanesala.me
ln -s /etc/nginx/sites-available/pos.kushanesala.me /etc/nginx/sites-enabled/pos.kushanesala.me
nginx -t
systemctl reload nginx
certbot --nginx -d pos.kushanesala.me
```

After Certbot finishes, open `https://pos.kushanesala.me` and verify the API:

```bash
curl -fsS https://pos.kushanesala.me/api/health
```

## Safe updates

```bash
cd /var/www/grocery-erp
git pull --ff-only origin main
docker compose up -d --build
docker compose ps
```

The API entrypoint runs pending migrations and rebuilds Laravel production caches. It does not seed unless `RUN_DATABASE_SEEDER=true` is explicitly configured.

## phpMyAdmin database dashboard

phpMyAdmin is optional and binds only to VPS localhost. It is not exposed through `pos.kushanesala.me` or directly through the public server IP.

Start it on the VPS only when database access is needed:

```bash
cd /var/www/grocery-erp
docker compose --profile tools up -d phpmyadmin
docker compose ps phpmyadmin
```

Keep the following SSH command running in a separate Windows PowerShell window:

```powershell
ssh -L 8081:127.0.0.1:8081 root@162.35.172.25
```

Then open `http://127.0.0.1:8081` in a browser. Sign in with:

- Username: `grocery_erp_user`
- Password: the value of `DB_PASSWORD` in `/var/www/grocery-erp/.env`
- Database: `grocery_erp`

The server is already fixed to `host.docker.internal`; phpMyAdmin does not allow choosing an arbitrary database host. Stop the dashboard when finished:

```bash
cd /var/www/grocery-erp
docker compose --profile tools stop phpmyadmin
```

The application does not display the database dashboard inside the ERP. This separation prevents a compromised ERP account from automatically granting raw database access. Access requires both VPS SSH credentials and the dedicated MySQL user password.

## Rollback

Application containers are replaceable. The database and API storage volume are not removed by a normal rebuild.

```bash
git log --oneline -10
git checkout <previous-commit>
docker compose up -d --build
```

Never use `docker compose down -v` in production because `-v` removes persistent volumes.
