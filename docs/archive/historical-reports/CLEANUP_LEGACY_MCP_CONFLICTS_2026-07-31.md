> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../contracts/AGENT_AND_MCP_CONTRACTS.md
> Purpose: implementation history only
# Cleanup legacy MCP conflicts â€” phase report

Status: Active supporting document (cleanup session `cleanup-legacy-mcp-conflicts`)  
Last verified: 2026-07-31  
Not a module MAP â€” see `SUPER_MAP_INDEX.md` for canonical maps.

## Code changes this session

- DISABLE: `api/ai/chat` + `api/ai/chat/models` (Global AI Chat HTTP)
- ADAPT: `SiteSyncInboundGateway::ingestCompatPush` â€” V2 writer skips links/keywords/scores enrich
- KEEP + document: three automation dispatchers (disjoint tables)
- KEEP: publish scheduler thin shell â†’ `ScheduledArticlePublishRunner`
- Docs: Agent Workspace phase handoffs â†’ `docs/archive/`

## Manual verification

```text
$PHP_BIN vendor/bin/phpunit --filter=AutomationDispatcherOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --filter=GlobalAiChatRouteRetiredContractTest
$PHP_BIN vendor/bin/phpunit --filter=SiteSyncCompatPushOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --filter=PublishScheduledArticlesCanonicalRunnerContractTest

php artisan route:list
php artisan schedule:list
php artisan event:list
php artisan list
php artisan optimize:clear
```
