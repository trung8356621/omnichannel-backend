# GSC Query & Page Mapping

## Query → Keyword (`GscQueryKeywordMapper`)

Precedence: manual exact → normalized exact → near-duplicate (`GscQueryNormalizationService::isNearDuplicate` → `KeywordNormalizationService::isNearDuplicate`) → `unmapped`.

## Page → Article (`GscPageArticleMapper`)

Precedence: manual → exact_canonical → exact_wp → slug.

Rules:

- No cross-site candidates
- No auto-map when multiple refs tie → `gsc.page_mapping_ambiguous`
- Exact URL match only for canonical/wp — **no** host substring / contains attack
- Trailing slash normalized via `GscPageNormalizationService` / `SerpUrlNormalizationService`

Commands: `MapGscQueryCommand`, `UnmapGscQueryCommand`, `MapGscPageCommand`, `UnmapGscPageCommand`.

Manual mappings: `metadata.manual = true` + `mapping_type = manual`. `MapGsc*Handler` và `GscSuggestedMappingPersistService` **không** ghi đè manual khi sync auto-map.

Sync path: mapper suggestions → optional persist candidate rows; durable manual maps chỉ qua Map commands.

Manual mappings preserved on opportunity → content project conversion.
