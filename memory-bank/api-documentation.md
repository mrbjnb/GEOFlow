# API Documentation: GEOFlow

## Overview

GEOFlow exposes several API surfaces:

1. **Admin Web Routes** — Blade-based admin dashboard (under `ADMIN_BASE_PATH`)
2. **Public Web Routes** — Front-end article site
3. **Internal API Routes** — JSON API for in-system consumption
4. **External API Routes** — Target-site health checks and content management
5. **WebSocket Channels** — Real-time updates via Laravel Reverb

## Route Structure

### Web Routes (`routes/web.php`)
- Public front-end for article display
- Admin dashboard at `/{ADMIN_BASE_PATH}/*`

### API Routes (`routes/api.php`)
- Internal API endpoints for admin operations
- External endpoints for target-site communication

### WebSocket Channels (`routes/channels.php`)
- Private channels for admin real-time updates
- Task progress broadcasting

### Console Routes (`routes/console.php`)
- Artisan commands for maintenance and operations

## Key API Categories

### AI Configuration
- Model CRUD (providers, models, credentials)
- Provider health check / test connection

### Knowledge Base
- Document upload and management
- Chunk management (view, re-chunk)
- Vectorization status

### Task Management
- Task CRUD
- Task status monitoring
- Task run history
- Manual task trigger / cancel

### Article Management
- Article CRUD
- Review workflow (approve, reject, request changes)
- Publishing (immediate, scheduled)
- Trash and restore

### Distribution
- Channel CRUD (GEOFlow Agent, WordPress REST, Generic HTTP API)
- Channel secret management
- Distribution log viewing
- Remote content management (edit, delete)
- Connection testing

### Analytics
- System overview metrics
- Content production stats
- Distribution status
- Access logs
- AI crawler recognition

### System
- Update checking
- Update center (view, backup, apply, rollback)
- Site settings
- System logs
- Admin management

## Authentication

### Admin API
- Session-based authentication via Laravel Sanctum
- Admin login at `/{ADMIN_BASE_PATH}/login`

### External API (Distribution Channels)
- Channel-specific authentication:
  - **GEOFlow Agent**: Signed requests with shared secret
  - **WordPress REST**: WordPress application passwords (JWT/basic auth)
  - **Generic HTTP API**: Configurable API key/header

## Response Format

Standard JSON API responses:

```json
{
    "data": { ... },
    "message": "Success message",
    "errors": { ... }
}
```

## Error Handling

- HTTP status codes following REST conventions
- Validation errors return 422 with field-level messages
- Authentication errors return 401/403
- Server errors return 500 with logged context

## Rate Limiting

- Configurable via Laravel's built-in rate limiter
- Admin API: higher limits for internal operations
- External API: standard limits per IP/channel
