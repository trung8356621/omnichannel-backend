> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Application API

Single execution path: Filament / Publishing Queue / Scheduler / REST / Agent â†’ `ContentProjectCommandBus`.

Do **not** update publish/lifecycle state from Filament callbacks or controllers outside Commands.  
Do **not** expose `SeoProjectRun` / Run Item / queue tokens to API or Agent.

See also: [CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md](CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md).

## Architecture

| Piece | Path |
|---|---|
| Commands | `app/Addons/SeoContentAi/Services/ContentProject/Application/Commands` |
| Handlers | `.../Handlers` |
| Bus | `ContentProjectCommandBus` + `ContentProjectCommandBusRegistrar` |
| Result | `ContentProjectActionResult` (`toApiArray()` for REST) |
| Public refs | `ContentProjectPublicRef` â€” API uses **strict** opaque refs |
| Publisher | `ContentPublisher` â†’ `WordPressContentPublisher` |
| Support | tenant, lock (owner+TTL), idempotency, preview, audit, transition guard, operation logger |

## REST `/api/v1`

Auth: `auth:sanctum` + `SetDynamicSeoDatabase`.

Success/error shape via `toApiArray()`:

- `success`, `code`, `message`, `data` (refs only), `warnings`, `errors`, `meta.request_id`, `meta.idempotent_replay`

HTTP: 200/201 success Â· 202 processing Â· 409 lock/lifecycle/confirmation Â· 422 validation Â· 403 forbidden Â· 404 not found Â· 429 quota Â· 503 wordpress unavailable.

Idempotency: header `Idempotency-Key`.

## Internal never expose

- `seo_project_runs` / Run Item
- Queue token / lock key / stop token
- Numeric IDs in API `data` (use `*_ref`)
- Direct WordPress client outside `ContentPublisher`
