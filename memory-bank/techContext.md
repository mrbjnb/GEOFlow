# GEOFlow — Technical Context

## Development Environment
- **OS**: Windows (win32)
- **Docker**: Desktop 29.5.2 with WSL2 backend
- **Git**: 2.54.0

## Tech Stack

| Component | Technology | Version |
|-----------|-----------|---------|
| Framework | Laravel | 12.56.0 |
| Language | PHP | 8.4 |
| Database | PostgreSQL + pgvector | pg16 |
| Cache/Queue | Redis | 7-alpine |
| Frontend | Blade + Tailwind CSS | - |
| WebSocket | Laravel Reverb | - |
| Build Tool | Docker Compose | V2 |

## Docker Services
| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| postgres | pgvector/pgvector:pg16 | 15432 | Database |
| redis | redis:7-alpine | 16379 | Cache/Queue |
| app | geoflow-app:latest | 18080 | Laravel web |
| queue | geoflow-app:latest | - | Queue worker |
| scheduler | geoflow-app:latest | - | Task scheduler |
| reverb | geoflow-app:latest | 18081 | WebSocket server |
| init | geoflow-app:latest | - | One-time init |

## Known Configuration (from .env)
- `APP_ENV=production`, `APP_DEBUG=false`
- `DB_CONNECTION=pgsql`
- `SESSION_DRIVER=database`
- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=redis`

## Performance Optimizations Applied
1. OPcache enabled for CLI mode (`opcache.enable_cli=1`)
2. `AUTO_OPTIMIZE=true` — caches config, events, routes, views on startup
3. `COMPOSER_ON_START=false` — skips redundant composer install
4. `AUTO_MIGRATE=false` — skips redundant migrate check on app restart
5. PostgreSQL switched to Docker named volume (`pgdata`) — faster I/O on Windows
