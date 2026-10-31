# Domain Monitor

Self-hosted monitoring for domain expiry, SSL certificates and DNS changes, with alerts by email, Telegram, Discord, Slack, Mattermost, Pushover, Gotify, ntfy, WhatsApp (WACRM), SMS (MSG91) and webhooks.

Source, issues and full docs: [github.com/ispgrid/domain-monitor](https://github.com/ispgrid/domain-monitor)

![Dashboard](https://raw.githubusercontent.com/ispgrid/domain-monitor/main/.github/screenshots/dashboard.png)

## Quick start

1. In an empty folder on the server, save [`docker-compose.yml`](https://github.com/ispgrid/domain-monitor/blob/main/docker-compose.yml). It uses `__IMAGE__:latest`.
2. Next to it, create a file named `.env` with two passwords:
   ```
   DB_PASSWORD=change-me
   DB_ROOT_PASSWORD=change-me-too
   ```
3. Run `docker compose up -d`, then open `http://SERVER_IP:8080` to run the installer.

The image has the app, its PHP packages and a scheduler for the checks built in, so there are no cron jobs to set up. It runs on amd64 and arm64.

## Updating

Back up the database, then:

```bash
docker compose pull && docker compose up -d
```

Open **Database update** in the app if the new version asks for it.

## Tags

| Tag | What it is |
|---|---|
| `latest` | Newest release |
| `1.9`, `1.9.0` | A release line or one exact release |
| `dev` | Built from the development branch for testing, not for production |

The same image is published at `ghcr.io/ispgrid/domain-monitor`.

## Data

Settings, uploads and logs live in the `/data` volume and the database in the `db` volume. Both survive updates; `docker compose down -v` deletes them.

## Settings

| Environment | What it does |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection |
| `TRUSTED_PROXIES` | Address or network of your reverse proxy, so the app sees visitor IPs and HTTPS |
| `ACCESS_LOG_FILE=true` | Keep the web access log in **Logs → Container** |
| `SCHEDULER=false` | Turn off the built-in scheduler |
| `TZ` | Container timezone |

Put the container behind a reverse proxy (Caddy, nginx, Traefik) for HTTPS; passkeys need it.

MIT licensed. Based on [Hosteroid/domain-monitor](https://github.com/Hosteroid/domain-monitor).
