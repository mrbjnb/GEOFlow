# GEOFlow — System Patterns

## Architecture
- **Framework**: Laravel 12 (PHP 8.4)
- **Frontend**: Server-rendered Blade + Tailwind CSS (not SPA)
- **Database**: PostgreSQL 16 + pgvector extension
- **Cache/Queue**: Redis 7 + Laravel Horizon
- **Realtime**: Laravel Reverb (WebSocket)
- **Deployment**: Docker Compose

## Key Architectural Patterns

### Service Layer Pattern
Business logic lives in `app/Services/` rather than in controllers. Core services are registered as singletons:
- `TaskLifecycleService`
- `WorkerExecutionService`
- `DistributionOrchestrator`

### Queue-Driven Execution
Long-running tasks (AI generation, distribution) run through Laravel Queue + Horizon, not in the HTTP request cycle.

### Runtime AI Provider Registration
Instead of static `config/ai.php`, the system injects per-task API credentials at runtime via `Config::set()`, allowing different tasks to use different OpenAI/Gemini/DeepSeek accounts.

### State Machine Pattern (TaskRuns)
The `task_runs` table is the single source of truth for execution state (pending → running → succeeded/failed). The queue only provides transport.

### Strategy Pattern (Distribution)
```php
DistributionPublisherInterface
├── GeoFlowAgentPublisher
├── WordPressRestPublisher
└── GenericHttpApiPublisher
```

### Entrypoint Pattern
`docker/entrypoint.sh` handles:
1. Composer install (on start, controlled by `COMPOSER_ON_START`)
2. APP_KEY generation (controlled by `AUTO_GENERATE_APP_KEY`)
3. Database wait (for PostgreSQL, controlled by `DB_CONNECTION`)
4. Migration (controlled by `AUTO_MIGRATE`)
5. Seeding (controlled by `AUTO_SEED`)
6. Optimization (controlled by `AUTO_OPTIMIZE`)
