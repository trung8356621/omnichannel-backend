> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Query & Page Mapping

## Query â†’ Keyword (`GscQueryKeywordMapper`)

Precedence: manual exact â†’ normalized exact â†’ near-duplicate (`GscQueryNormalizationService::isNearDuplicate` â†’ `KeywordNormalizationService::isNearDuplicate`) â†’ `unmapped`.

## Page â†’ Article (`GscPageArticleMapper`)

Precedence: manual â†’ exact_canonical â†’ exact_wp â†’ slug.

Rules:

- No cross-site candidates
- No auto-map when multiple refs tie â†’ `gsc.page_mapping_ambiguous`
- Exact URL match only for canonical/wp â€” **no** host substring / contains attack
- Trailing slash normalized via `GscPageNormalizationService` / `SerpUrlNormalizationService`

Commands: `MapGscQueryCommand`, `UnmapGscQueryCommand`, `MapGscPageCommand`, `UnmapGscPageCommand`.

Manual mappings: `metadata.manual = true` + `mapping_type = manual`. `MapGsc*Handler` vÃ  `GscSuggestedMappingPersistService` **khÃ´ng** ghi Ä‘Ã¨ manual khi sync auto-map.

Sync path: mapper suggestions â†’ optional persist candidate rows; durable manual maps chá»‰ qua Map commands.

Manual mappings preserved on opportunity â†’ content project conversion.
