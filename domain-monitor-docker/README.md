# Domain Monitor Docker Setup

This directory contains Docker configuration for running Domain Monitor in a containerized environment.

> **New installs:** use the published image instead (`ghcr.io/ispgrid/domain-monitor`, see **Docker** in the main README). It has the cron jobs built in and keeps your data in one volume. This bootstrap setup keeps working for existing installs, and its code is a git clone, so the in-app updater still works here.

## Quick Start

1. **Configure your environment:**
   - Copy `env.docker.example` to `.env.docker`
   - Edit `.env.docker` and change the default passwords:
     ```bash
     cp env.docker.example .env.docker
     # Edit .env.docker with your real passwords
     ```

2. **Run the bootstrap script:**
   ```bash
   chmod +x bootstrap.sh
   ./bootstrap.sh
   ```

## What the bootstrap script does

The `bootstrap.sh` script will:

- **Clone the Domain Monitor repository** from GitHub into the `app/` folder (only if `app/` doesn't exist or is empty)
- **Set up the application environment** by creating `.env` file with database configuration
- **Install PHP dependencies** using Composer
- **Configure proper file permissions** for the web server
- **Load environment variables** from `.env.docker` into the shell
- **Start the Docker stack** (web server, database, and phpMyAdmin)

### Running bootstrap multiple times

The bootstrap script is **safe to run multiple times**:
- If `app/` folder exists and has content, it skips the git clone
- It will reinstall Composer dependencies (ensures they're up to date)
- It will restart the Docker containers with `--build` flag
- Your database data is preserved (stored in Docker volumes)

## Environment Configuration

The bootstrap script loads environment variables from `.env.docker` into the shell environment, which Docker Compose then uses for variable substitution. This approach ensures that all services get the correct database credentials.

## Services

After running the bootstrap script, you'll have:

- **Domain Monitor App**: http://localhost:8080
- **phpMyAdmin**: http://127.0.0.1:8081 (Server: `domain-monitor-mariadb`)

phpMyAdmin only listens on the host itself, so the database login page isn't open to the network. From another machine, use an SSH tunnel and then open http://127.0.0.1:8081 locally:

```bash
ssh -L 8081:127.0.0.1:8081 user@your-server
```

To expose it anyway (not recommended), add `PMA_BIND=0.0.0.0` to `.env.docker` and re-run `./bootstrap.sh`.

### Passwords and secrets

- `.env.docker` is read by the shell. Put values containing spaces or `& # $ ;` in single quotes, e.g. `DB_PASSWORD='p&ss#word'`, and keep the file private with `chmod 600 .env.docker`.
- The bootstrap writes the values to `app/.env` quoted, so any character works.
- Each run backs up the old `app/.env` as `app/.env.bak.<timestamp>`, readable by root only. Delete old backups you don't need.

## Managing the Docker Stack

### Starting the stack
```bash
# First time setup (clones repo, installs dependencies, starts containers)
./bootstrap.sh

# Or if you just want to start existing containers
docker compose up -d
```

### Stopping the stack
```bash
# Stop all containers
docker compose down

# Stop and remove volumes (WARNING: This will delete your database data!)
docker compose down -v
```

### Restarting the stack
```bash
# Restart containers (keeps data)
docker compose restart

# Rebuild and restart (if you made changes to Docker config)
docker compose up -d --build

# Full restart (stops, rebuilds, starts)
docker compose down && docker compose up -d --build
```

### Viewing logs
```bash
# View logs for all services
docker compose logs

# View logs for specific service
docker compose logs web
docker compose logs db
docker compose logs pma

# Follow logs in real-time
docker compose logs -f
```

## Environment Configuration

You need to create a `.env.docker` file with your database credentials. The script will copy `env.docker.example` to `.env.docker` if it doesn't exist, but you **must edit it** with real passwords before the script will work.

Required variables in `.env.docker`:
- `DB_DATABASE` - Database name
- `DB_USERNAME` - Database user
- `DB_PASSWORD` - Database password  
- `DB_ROOT_PASSWORD` - Database root password
- `TZ` - Timezone (optional, defaults to UTC)

## Requirements

- Docker and Docker Compose
- Git
- Internet connection (to clone the repository)

## Troubleshooting

- Make sure you're running the script from the `domain-monitor-docker` directory
- Ensure Docker is running and you have permission to use it
- Check that you've edited `.env.docker` with real passwords (not the example values)

## Quick Reference

### Common scenarios

**First time setup:**
```bash
cp env.docker.example .env.docker
# Edit .env.docker with real passwords
./bootstrap.sh
```

**Daily usage:**
```bash
# Start (if stopped)
docker compose up -d

# Stop
docker compose down
```

**After making changes to the app:**
```bash
# Restart to pick up changes
docker compose restart web
```

**After making changes to Docker config:**
```bash
# Rebuild containers
docker compose up -d --build
```

**Complete reset (WARNING: deletes all data):**
```bash
docker compose down -v
rm -rf app/
./bootstrap.sh
```
