> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Google Search Console â€” API Connections (tá»•ng káº¿t triá»ƒn khai)

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md) Â· [Settings & API Connections](MAP_SEO_SETTINGS.md) Â· [Performance Hub](MAP_SEO_PERFORMANCE_HUB.md)

> Doc nÃ y tá»•ng há»£p **toÃ n bá»™ thay Ä‘á»•i GSC** trong batch API Connections (OAuth riÃªng, route `{id}`, UI edit/create). Äá»c doc nÃ y trÆ°á»›c khi debug â€œdá»¯ liá»‡u cÅ© máº¥tâ€ hoáº·c â€œUI saiâ€.

---

## 1. Bá»‘i cáº£nh & má»¥c tiÃªu

| TrÆ°á»›c | Sau (hiá»‡n táº¡i) |
|-------|----------------|
| Edit GSC khÃ´ng cÃ³ `{id}` trong URL | Má»—i connection cÃ³ URL riÃªng `.../google-search-console/{id}/edit` |
| OAuth dÃ¹ng chung Google Login (náº¿u cÃ³) | OAuth **riÃªng** `GOOGLE_SEARCH_CONSOLE_*` |
| Bar nÃºt action bá»‹ render 2 láº§n (Filament header + blade) | Chá»‰ 1 bar (áº©n Filament header) |
| List GSC cÃ³ thá»ƒ chá»‰ 1 dÃ²ng | List hiá»‡n **má»i** row trong `seo_gsc_master_connections` |
| Form create vs edit khÃ¡c nhau máº¡nh | DÃ¹ng chung `ApiConnectionFormSchema`, edit bá»• sung status/email/property |

**ChÆ°a hoÃ n thiá»‡n 100%:** UI/list/OAuth Ä‘Ã£ theo `{id}`, nhÆ°ng **má»™t sá»‘ luá»“ng sync & Performance Hub váº«n láº¥y connection Ä‘áº§u tiÃªn** (`resolveForUser()`). Xem Â§8.

---

## 2. Kiáº¿n trÃºc dá»¯ liá»‡u

### 2.1 Master + mapping (multi-domain)

```
seo_gsc_master_connections (1 row = 1 Google account / 1 credential set)
    â””â”€â”€ seo_gsc_property_mappings (n row: gsc_connection_id + site_id â†’ property_url)
```

| Báº£ng | Connection DB | MÃ´ táº£ |
|------|---------------|-------|
| `seo_gsc_master_connections` | **`mysql` (core)** | name, status, `oauth_client_id`, `oauth_client_secret` (encrypted), `credentials` (encrypted JSON tokens), `account_email`, `metadata.properties` |
| `seo_gsc_property_mappings` | **`mysql` (core)** | map `site_id` (domain header) â†’ GSC property URL |

**LÆ°u Ã½:** OAuth/master/mapping tables **khÃ´ng** náº±m trÃªn `omi_seo_ai`. Migration: `app/Addons/SeoContentAi/database/migrations/2026_07_11_100000_create_seo_external_api_connections_tables.php`.

### 2.2 Dá»¯ liá»‡u Performance Hub (legacy snapshot)

KPI/query **legacy** trÃªn Performance Hub Ä‘á»c **site meta** `gsc_query_snapshot` (báº£ng meta site core), **khÃ´ng** Ä‘á»c trá»±c tiáº¿p `seo_gsc_master_connections`.

Luá»“ng ghi snapshot: `GoogleSearchConsoleSyncService::syncSite()` â†’ GSC API â†’ `Site::setMeta('gsc_query_snapshot', ...)`.

Náº¿u API fail â†’ fallback `syncFromLegacySnapshot()` (chá»‰ kiá»ƒm tra meta cÅ© cÃ²n hay khÃ´ng).

### 2.3 GSC Intelligence facts (Phase 5 â€” tÃ¡ch biá»‡t)

Canonical Search Analytics facts / mappings / opportunities náº±m trÃªn connection **`omi_seo_ai`** (migration `2026_07_28_180000_create_gsc_intelligence_tables.php`). KhÃ´ng duplicate OAuth credential tá»« master connections.

Xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md) + [GSC_DATA_MODEL.md](GSC_DATA_MODEL.md). Overlay UI additive trÃªn Performance Hub â€” **chÆ°a** thay snapshot legacy.

---

## 3. Routes & URL

Base: `/seo/{connection_hash}/settings/api/...`

| Má»¥c Ä‘Ã­ch | Route name | Path |
|----------|------------|------|
| List | `filament.seo.resources.settings.api.index` | `/` |
| Create | `...create` | `/create` |
| **Edit GSC** | `...edit-gsc` | `/google-search-console/{record}/edit` |
| **OAuth start** | `seo.gsc.oauth.redirect` | `/google-search-console/{record}/connect` |
| OAuth callback (global) | `seo.gsc.oauth.callback` | `/seo/oauth/google-search-console/callback` |
| Legacy redirect | `gsc-edit-legacy` | `/gsc/edit` â†’ redirect cÃ³ `{id}` |
| Legacy redirect | `gsc-edit-root-legacy` | `/google-search-console/edit` â†’ redirect cÃ³ `{id}` |
| Edit DataForSEO | `edit-dataforseo` | `/dataforseo/edit` |
| Edit AI | `edit` | `/{record}/edit` |

**Route conflict Ä‘Ã£ xá»­ lÃ½:** path `google-search-console/{record}/edit` Ä‘Äƒng kÃ½ **trÆ°á»›c** `/{record}/edit` Ä‘á»ƒ `gsc` khÃ´ng bá»‹ nuá»‘t lÃ m record AI.

Helper URL: `AiConnectionResource::gscEditUrl($id, ?$connectionHash)` â€” build path tÆ°á»ng minh `/seo/{hash}/settings/api/google-search-console/{id}/edit` khi cÃ³ hash (OAuth callback).

---

## 4. OAuth flow (tÃ¡ch Google Login)

### 4.1 Env & credential per connection

**Server (`.env` / `config/services.php`):** chá»‰ cáº§n redirect URI global:

```env
GOOGLE_SEARCH_CONSOLE_REDIRECT_URI=https://<host>/seo/oauth/google-search-console/callback
```

**Per master connection (DB `seo_gsc_master_connections`):** Manager nháº­p **OAuth Client ID + Client secret** trÃªn form create/edit. Runtime OAuth (`GoogleSearchConsoleOAuthService::resolveOAuthApp()`) Ä‘á»c tá»« connection, **khÃ´ng** dÃ¹ng `GOOGLE_SEARCH_CONSOLE_CLIENT_ID/SECRET` env.

Google Cloud Console: redirect URI pháº£i khá»›p **chÃ­nh xÃ¡c** callback trÃªn.

**OAuth scope:** `webmasters.readonly` + `userinfo.email` (`GoogleSearchConsoleOAuthService::SCOPE`).

### 4.2 Luá»“ng

```mermaid
sequenceDiagram
    participant U as Manager
    participant E as EditGscApiConnection
    participant O as GoogleSearchConsoleOAuthController
    participant G as Google OAuth
    participant S as GoogleSearchConsoleOAuthService

    U->>E: Connect / Reconnect
    E->>O: GET .../google-search-console/{id}/connect
    O->>S: beginAuthorization(connectionId, returnUrl)
    O->>G: redirect authorization URL
    G->>O: callback ?code=&state=
    O->>S: exchange code, persist tokens + email, testConnection
    O->>S: sync properties â†’ metadata
    O->>E: redirect .../google-search-console/{id}/edit (Æ°u tiÃªn connection_id + hash)
```

Session key: `gsc_oauth_pending` (state, user_id, connection_hash, **connection_id**, return_url).

**Callback redirect:** `GoogleSearchConsoleOAuthController::resolveTargetUrlFromContext()` â€” Æ°u tiÃªn `connection_id` â†’ `gscEditUrl()`; `SeoConnectionContext::rememberHash()` restore `seo_current_connection_hash` sau OAuth (trÃ¡nh vÄƒng vá» list `/settings/api`).

**Status sau OAuth:** `GoogleSearchConsoleConnectionService::resolveEffectiveStatus()` â€” Connected khi cÃ³ oauth app + access + refresh (email optional, chá»‰ hiá»ƒn thá»‹). `testConnection()` dÃ¹ng `hasApiTokens()` / `canCallGscApi()`, khÃ´ng báº¯t email.

Files:
- `Http/Controllers/GoogleSearchConsoleOAuthController.php`
- `Services/GoogleSearchConsoleOAuthService.php`
- Route Ä‘Äƒng kÃ½: `Providers/SeoPanelProvider.php`

---

## 5. UI Filament

### 5.1 Trang & view

| Trang | Class | View |
|-------|-------|------|
| List | `ListAiConnections` | `seo-settings-api-list.blade.php` |
| Create (má»i provider) | `CreateAiConnection` | `seo-settings-ai-form.blade.php` |
| Edit GSC | `EditGscApiConnection` | `seo-settings-api-form.blade.php` |
| Edit DataForSEO | `EditDataForSeoApiConnection` | `seo-settings-api-form.blade.php` |

### 5.2 Form schema (`Support/ApiConnectionFormSchema.php`)

**Create (GSC):** Provider, Display name, OAuth Client ID/secret (required), callback URL (readonly+copy), hint â€œSave â†’ redirect edit â†’ Connectâ€.

**Edit (GSC)** â€” khi `gsc_has_saved_config = true` (luÃ´n set trÃªn mount edit):
- OAuth Client ID; Client secret (Ä‘á»ƒ trá»‘ng = giá»¯ cÅ©; hint `gsc_oauth_client_secret_saved` khi Ä‘Ã£ lÆ°u)
- Connection status (`resolveEffectiveStatus`)
- Account email (readonly, `dehydrated(false)` â€” optional cho status Connected)
- Token expiration
- Property URL dropdown (`gsc_available_properties` + mapping hiá»‡n táº¡i)
- **KhÃ´ng** nháº­p Access/Refresh token thá»§ cÃ´ng (OAuth-only)

### 5.3 Action bar (1 bar duy nháº¥t)

- Blade render: `getCachedHeaderActions()` trong `seo-settings-api-form.blade.php`
- Filament header bá»‹ táº¯t: trait `Filament/Concerns/HidesFilamentPageHeader.php` (`getHeader(): null`)

NÃºt trÃªn edit GSC: Connect / Reconnect, Disconnect, Sync properties, Test connection, Sync GSC for current domain.

### 5.4 List nhiá»u connection

`ApiConnectionsListService::recordsForUser()`:
- AI tá»« `api_connections`
- **Má»—i** GSC tá»« `GoogleSearchConsoleConnectionService::allForUser()`
- DataForSEO 1 row (váº«n single)

Row áº£o: `Models/ApiConnectionListRow` â€” key `gsc:{id}`; status list = `resolveEffectiveStatus()` (khÃ´ng Ä‘á»c cá»™t `status` thÃ´).

`ListAiConnections::notifyOAuthFlash()` â€” hiá»‡n toast `gsc_oauth_success` / `gsc_oauth_error` náº¿u callback redirect vá» list.

---

## 6. Services chÃ­nh

| Service | Vai trÃ² |
|---------|---------|
| `GoogleSearchConsoleConnectionService` | CRUD, mapping, `hasOAuthAppCredentials`, `hasApiTokens`, `canCallGscApi`, `hasUsableTokens`, `resolveEffectiveStatus`, `testConnection`, `resolveByIdForUser`, `allForUser` |
| `GoogleSearchConsoleOAuthService` | OAuth begin/callback, token refresh, disconnect |
| `GoogleSearchConsoleSyncService` | listProperties, `syncSiteWithDetails()` â†’ `gsc_query_snapshot` (schema: `property_url`, `date_start`, `date_end`, `filters`, `kpis.total_queries`, `timeseries.current/previous`, `chart_status`; dimension `date` 28d + previous period); resolve connection qua `seo_gsc_property_mappings.gsc_connection_id` |
| `GoogleSearchConsoleDomainMatcherService` | Normalize host, exact match property, priority `sc-domain` > https root > https www |
| `GoogleSearchConsoleBulkSyncService` | `ensureSiteMapped()` (auto-map 1 domain), `autoMapAndSyncAll()`, `syncAllMappedSites($autoMapFirst=true)`, `formatSummaryMessage()` â€” summary rows unmatched/ambiguous/failed |
| `Jobs/SyncGscSiteSnapshotJob` | Queue sync 1 site |
| `ApiConnectionsListService` | Gá»™p list Filament |

Edit page actions (`EditGscApiConnection`):
- **Refresh properties** (`refreshProperties`) â€” chá»‰ gá»i Sites API, cáº­p nháº­t metadata
- **Auto-map & Sync all domains** (`autoMapAndSyncAll`) â€” refresh â†’ auto-match â†’ upsert mappings â†’ sync tá»«ng site
- **Sync current domain** â€” giá»¯ nguyÃªn

---

## 7. ÄÃ£ fix (theo feedback user)

| # | Váº¥n Ä‘á» | CÃ¡ch xá»­ lÃ½ |
|---|--------|------------|
| 1 | Double action bar | `HidesFilamentPageHeader` + chá»‰ render actions trong blade |
| 2 | URL khÃ´ng cÃ³ id | `google-search-console/{record}/edit` + OAuth `{record}/connect` |
| 3 | Create vs edit quÃ¡ khÃ¡c | Chung `ApiConnectionFormSchema`; edit mount fill Ä‘á»§ field |
| 4 | Property dropdown trá»‘ng | Sync on mount + merge mapping hiá»‡n táº¡i vÃ o options |
| 5 | 404 khi edit GSC | TÃ¡ch route khá»i `/{record}/edit`; legacy redirect |
| 6 | Toast â€œThiáº¿u credentialâ€ sau OAuth | Scope `userinfo.email`; `testConnection` dÃ¹ng `hasApiTokens` thay vÃ¬ báº¯t email |
| 7 | Callback vÄƒng vá» `/settings/api` | `rememberHash` + `gscEditUrl($id, $hash)` + Æ°u tiÃªn `connection_id` trong redirect |
| 8 | Status Not configured dÃ¹ cÃ³ token | `hasUsableTokens` = access + refresh (email optional); list dÃ¹ng `resolveEffectiveStatus` |
| 9 | Sync all bÃ¡o OK nhÆ°ng domain chÆ°a map thá»§ cÃ´ng khÃ´ng sync | Hub + bulk dÃ¹ng `autoMapAndSyncAll` / `ensureSiteMapped()` trÆ°á»›c sync; toast summary `newly_matched/synced/failed/unmatched`; khÃ´ng fallback legacy im láº·ng khi API fail |

Tests:
- `tests/Unit/GoogleSearchConsoleOAuthTest.php`
- `tests/Unit/AiConnectionResourceEditUrlTest.php`
- `tests/Unit/AiConnectionResourceRouteConflictTest.php`
- `tests/Unit/GoogleSearchConsoleSyncTest.php`
- `tests/Unit/GoogleSearchConsoleBulkSyncTest.php`

---

## 8. Chá»— cÃ³ thá»ƒ â€œsai saiâ€ / gap cáº§n biáº¿t

### 8.1 Multi-connection: sync Ä‘Ã£ theo mapping

`GoogleSearchConsoleSyncService::syncSite()` resolve connection qua `seo_gsc_property_mappings.gsc_connection_id`. Performance Hub GSC strip dÃ¹ng mapping cá»§a domain hiá»‡n táº¡i.

Gap cÃ²n láº¡i: `LegacyGscEditRedirect` váº«n redirect vá» connection Ä‘áº§u tiÃªn náº¿u URL cÅ© khÃ´ng cÃ³ `{id}`.

### 8.2 â€œDá»¯ liá»‡u cÅ© Ä‘Ã¢u?â€ â€” 3 kháº£ nÄƒng

1. **ChÆ°a migrate báº£ng**  
   Náº¿u `seo_gsc_master_connections` chÆ°a tá»“n táº¡i / trá»‘ng â†’ form edit trá»‘ng.  
   Cháº¡y migration addon trÃªn **core mysql**.

2. **Dá»¯ liá»‡u cÅ© náº±m chá»— khÃ¡c**  
   Performance KPI cÃ³ thá»ƒ chá»‰ cÃ³ trong **site meta** `gsc_query_snapshot` (sync WP/plugin/manual trÆ°á»›c Ä‘Ã¢y), **khÃ´ng** tá»± copy sang `seo_gsc_master_connections`.  
   â†’ Hub váº«n cÃ³ KPI cÅ©; form API Connections váº«n trá»‘ng token/email.

3. **Save manual token trÆ°á»›c Ä‘Ã¢y**  
   - Token lÆ°u trong `credentials` (encrypted) váº«n cÃ²n náº¿u Ä‘Ã£ insert vÃ o báº£ng má»›i.  
   - UI **khÃ´ng hiá»ƒn thá»‹** token Ä‘Ã£ lÆ°u (chá»‰ placeholder trá»‘ng; field token chá»‰ khi `APP_DEBUG`).  
   - `account_email` Ä‘iá»n qua OAuth (scope `userinfo.email`); **khÃ´ng báº¯t buá»™c** Ä‘á»ƒ status Connected náº¿u Ä‘Ã£ cÃ³ access + refresh.

### 8.3 KhÃ´ng cÃ³ script migrate dá»¯ liá»‡u legacy

KhÃ´ng cÃ³ job/command copy credential tá»« storage cÅ© (wp_options, file, báº£ng khÃ¡c) sang `seo_gsc_master_connections`. Náº¿u user save â€œGSC keyâ€ á»Ÿ há»‡ thá»‘ng cÅ© chÆ°a map sang báº£ng má»›i â†’ **pháº£i Connect láº¡i** hoáº·c insert thá»§ cÃ´ng DB.

### 8.4 OAuth app chÆ°a lÆ°u trÃªn connection

`gsc_oauth_app_not_configured` / Connect disabled â†’ chÆ°a cÃ³ Client ID + secret trong DB. Edit: nháº­p secret â†’ **Save** trÆ°á»›c khi Connect.

### 8.5 Create flow 2 bÆ°á»›c

Create GSC **khÃ´ng** cÃ³ nÃºt Connect ngay. Flow: Create (name) â†’ redirect edit `{id}` â†’ Connect OAuth. KhÃ¡c UX â€œpaste token má»™t láº§nâ€ trÆ°á»›c Ä‘Ã¢y.

### 8.6 View create dÃ¹ng `seo-settings-ai-form`

Create API connection dÃ¹ng view AI form (khÃ´ng cÃ³ header actions) â€” Ä‘Ãºng cho create, nhÆ°ng tÃªn file gÃ¢y nháº§m (khÃ´ng pháº£i bug functional).

### 8.7 Property mapping gáº¯n domain header

`gsc_property_url` lÆ°u theo `SeoAccessControl::globalSiteId()` â€” Ä‘á»•i domain header trÃªn top bar = mapping khÃ¡c / dropdown khÃ¡c.

### 8.8 Domain chÆ°a map thá»§ cÃ´ng / Reauthorization

- **ChÆ°a map:** dÃ¹ng **Auto-map & Sync all** hoáº·c **Sync current domain** â€” `ensureSiteMapped()` match domain â†” GSC property (`GoogleSearchConsoleDomainMatcherService`). Unmatched/ambiguous â†’ map thá»§ cÃ´ng trÃªn Edit GSC.
- **Reauthorization required:** token háº¿t háº¡n â€” Reconnect OAuth trÆ°á»›c khi sync; bulk summary hiá»‡n `failed` thay vÃ¬ toast OK chung.

---

## 9. Checklist verify production

```bash
php artisan route:clear
php artisan optimize:clear
php artisan test app/Addons/SeoContentAi/tests/Unit/GoogleSearchConsoleOAuthTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/GoogleSearchConsoleOAuthCredentialsTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/GoogleSearchConsoleConnectionServiceTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/AiConnectionResourceEditUrlTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/AiConnectionResourceRouteConflictTest.php
```

**DB:**
```sql
SELECT id, user_id, name, status, account_email, last_error FROM seo_gsc_master_connections;
SELECT * FROM seo_gsc_property_mappings;
```

**UI:**
1. Settings â†’ API Connections â†’ Edit row GSC â†’ URL cÃ³ `/google-search-console/{sá»‘}/edit`
2. Sau Connect OAuth â†’ redirect vá» **edit** (khÃ´ng list), toast success
3. Chá»‰ **má»™t** hÃ ng nÃºt action phÃ­a trÃªn form
4. CÃ³ access + refresh â†’ status Connected (email cÃ³ thá»ƒ trá»‘ng táº¡m thá»i)
5. Property trá»‘ng â†’ Sync properties hoáº·c Reconnect
6. Äá»•i domain header â†’ chá»n property â†’ Save â†’ mapping Ä‘Ãºng `site_id`

**Env:** `GOOGLE_SEARCH_CONSOLE_REDIRECT_URI` khá»›p Google Cloud. Client ID/secret trÃªn form connection.

---

## 10. Viá»‡c nÃªn lÃ m tiáº¿p (náº¿u muá»‘n Ä‘Ãºng multi-GSC end-to-end)

| Æ¯u tiÃªn | Viá»‡c |
|---------|------|
| P0 | `syncSite($siteId)` resolve connection qua **mapping** `seo_gsc_property_mappings.site_id`, khÃ´ng `resolveForUser()` |
| P0 | `statusForSite($siteId)` tráº£ status connection **Ä‘Ã£ map** domain Ä‘Ã³ |
| P1 | Performance Hub connection strip: link edit Ä‘Ãºng `gsc:{id}` |
| P1 | Command migrate/import credential legacy (náº¿u xÃ¡c Ä‘á»‹nh Ä‘Æ°á»£c storage cÅ©) |
| P2 | Create form: optional â€œConnect ngayâ€ sau create |
| P2 | Hiá»‡n indicator secret Ä‘Ã£ lÆ°u | âœ… hint `gsc_oauth_client_secret_saved` trÃªn edit |

---

## 11. File map nhanh

| File | Vai trÃ² |
|------|---------|
| `Filament/Resources/AiConnectionResource.php` | Routes, `gscEditUrl`, list edit URL |
| `Filament/Resources/.../EditGscApiConnection.php` | Edit page, mount, actions, save |
| `Filament/Resources/.../CreateAiConnection.php` | Create GSC â†’ redirect edit |
| `Filament/Resources/.../LegacyGsc*Redirect.php` | URL cÅ© â†’ cÃ³ id |
| `Filament/Concerns/HidesFilamentPageHeader.php` | Fix double bar |
| `Support/ApiConnectionFormSchema.php` | Form create/edit |
| `Services/GoogleSearchConsoleConnectionService.php` | Domain logic connection |
| `Services/GoogleSearchConsoleOAuthService.php` | OAuth |
| `Services/GoogleSearchConsoleSyncService.php` | API + snapshot |
| `Services/ApiConnectionsListService.php` | List merge |
| `Http/Controllers/GoogleSearchConsoleOAuthController.php` | HTTP OAuth |
| `Models/SeoGscMasterConnection.php` | Model master |
| `Models/SeoGscPropertyMapping.php` | Model mapping |
| `Models/ApiConnectionListRow.php` | Row áº£o list |
| `Support/SeoConnectionContext.php` | `rememberHash()`, `panelPath`, merge route params |
| `Filament/Resources/.../ListAiConnections.php` | List + OAuth flash toast |
| `Providers/SeoPanelProvider.php` | OAuth route `{record}` + callback |
| `resources/views/filament/pages/seo-settings-api-form.blade.php` | Layout edit + action bar |

---

*Cáº­p nháº­t: 2026-07-11 â€” OAuth per-connection credentials, callback redirect fix, status Connected khÃ´ng báº¯t email.*
