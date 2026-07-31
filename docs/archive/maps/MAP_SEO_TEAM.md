> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_MCP_AND_DOMAINS.md
> Purpose: implementation history only
# SeoContentAi â€” Team & Authorization

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor & EditArticle](MAP_SEO_EDITOR.md) Â· [Settings & Prompt](MAP_SEO_SETTINGS.md) Â· [Content Projects](MAP_SEO_PROJECTS.md)

---

## 1. Há»‡ thá»‘ng phÃ¢n quyá»n (RBAC)

### 1.1 User Model Roles

User Laravel core (`App\Models\User`) cÃ³ 2 lá»›p role:

**Role há»‡ thá»‘ng** (`users.role`):
| Role | Constant | MÃ´ táº£ |
|------|----------|-------|
| `admin` | `User::ROLE_ADMIN` | Super admin â€” toÃ n quyá»n, cÃ³ thá»ƒ view panel cá»§a má»i connection (read-only) |
| `owner` | `User::ROLE_OWNER` | Chá»§ tÃ i khoáº£n â€” full quyá»n trÃªn account vÃ  team cá»§a mÃ¬nh |
| `manager` | `User::ROLE_MANAGER` | Quáº£n lÃ½ thuá»™c má»™t Owner (`parent_id`); quáº£n lÃ½ Staff qua `manager_id` |
| `staff` | `User::ROLE_STAFF` | ThÃ nh viÃªn team â€” `parent_id` = Owner; `manager_id` nullable (chÆ°a gÃ¡n Manager) |

### 1.1b Organizational hierarchy (Owner â†’ Manager â†’ Staff)

Quan há»‡ tá»• chá»©c **khÃ´ng thay RBAC / `seo_role`**. Schema:

| Cá»™t | Ã nghÄ©a |
|-----|---------|
| `parent_id` | Owner FK (giá»¯ tÃªn cÅ©; khÃ´ng thÃªm `owner_id` trÃ¹ng) |
| `manager_id` | Manager FK cá»§a Staff; `nullOnDelete` |

Rules: Owner/Admin â†’ cáº£ hai null; Manager â†’ `parent_id` báº¯t buá»™c, `manager_id` null; Staff â†’ `parent_id` báº¯t buá»™c, `manager_id` optional (Manager pháº£i thuá»™c cÃ¹ng Owner).

| Symbol | Vai trÃ² | Path |
|--------|---------|------|
| `User::owner()` / `manager()` / `managers()` / `staffMembers()` / `directStaffMembers()` | Eloquent hierarchy | `app/Models/User.php` |
| `User::accountOwnerId()` | Owner self; Manager/Staff â†’ `parent_id` | `app/Models/User.php` |
| `UserHierarchyService` | Normalize/validate form; block xÃ³a Owner cÃ²n team; detach Staff khi xÃ³a/Ä‘á»•i Manager | `app/Services/Users/UserHierarchyService.php` |
| `UserResource` (Admin) | Form Owner/Manager theo role; filter Role/Owner/Manager/Unassigned staff; group `owner.name`; scope Owner chá»‰ team mÃ¬nh | `app/Filament/Resources/UserResource.php` |
| Migration `manager_id` | Index + FK nullable | `database/migrations/2026_07_29_100000_add_manager_id_to_users_table.php` |

**Role SEO** (`users.seo_role`):
| Role | Constant | Rank | MÃ´ táº£ |
|------|----------|------|-------|
| `content_manager` | `SeoAccessControl::ROLE_CONTENT_MANAGER` | 1 | Viáº¿t bÃ i, view project cá»§a mÃ¬nh, khÃ´ng dÃ¹ng AI, khÃ´ng chá»n global site |
| `planner` | `SeoAccessControl::ROLE_PLANNER` | 2 | Láº­p káº¿ hoáº¡ch, chá»n global site/project, cÃ³ thá»ƒ sim xuá»‘ng content_manager |
| `manager` | `SeoAccessControl::ROLE_MANAGER` | 3 | Full quyá»n, cáº¥u hÃ¬nh settings, quáº£n lÃ½ team, cÃ³ thá»ƒ sim xuá»‘ng planner hoáº·c content_manager |

### 1.2 Role Simulation

Manager/Planner cÃ³ thá»ƒ **simulate** role tháº¥p hÆ¡n qua session:

```mermaid
flowchart LR
    ADMIN["admin<br/>CÃ³ thá»ƒ view má»i connection"]
    ADMIN -->|"Khi xem panel cá»§a connection khÃ¡c"| READONLY["READ-ONLY<br/>isSeoPanelReadOnly() = true"]

    OWNER["owner<br/>(full role)"]
    OWNER -->|"seo_role = manager"| MGR["manager<br/>Rank 3"]
    MGR -->|"simulate"| PL["planner<br/>Rank 2"]
    MGR -->|"simulate"| CM["content_manager<br/>Rank 1"]
    PL -->|"simulate"| CM
```

- **`allowedSimulationTargets()`**: Manager â†’ [manager, planner, content_manager]; Planner â†’ [planner, content_manager]; Content Manager â†’ [content_manager]
- **`SeoAccessControl::effectiveRole()`**: tráº£ vá» role hiá»‡u dá»¥ng (cÃ³ tÃ­nh simulation)
- **`SeoAccessControl::isSeoPanelReadOnly()`**: Admin xem panel cá»§a connection khÃ¡c

### 1.3 Permission Matrix

| Permission Method | content_manager | planner | manager | admin (panel viewer) |
|---|---|---|---|---|
| `canAccessContentFeatures()` | âœ… | âœ… | âœ… | âœ… |
| `canAccessPlannerFeatures()` | âŒ | âœ… | âœ… | âœ… |
| `canAccessManagerFeatures()` | âŒ | âŒ | âœ… | âŒ |
| `canMutateInSeoPanel()` | âœ… | âœ… | âœ… | âŒ (read-only) |
| `canMutateContentProjects()` | âŒ | âœ… | âœ… | âŒ |
| `canSyncArticlesToWordPress()` | âŒ | âœ… | âœ… | âŒ |
| `canDeleteSeoMedia()` | âŒ | âœ… | âœ… | âŒ |
| `isContentManager()` | âœ… | âŒ | âŒ | âŒ |
| `shouldShowGlobalSeoBar()` | âŒ | âœ… | âœ… | âœ… |
| `canUseGlobalContentProjectPicker()` | âŒ | âœ… | âŒ | âŒ |
| `canManageWordPressPlugin()` | âŒ | âŒ | âŒ | âŒ (chá»‰ Admin core) |

### 1.4 Site & Data Scoping

**Global Site Scope:**
- `shouldApplyGlobalSiteScope()`: true vá»›i Planner/Manager Ä‘Ã£ chá»n global site â€” **chá»‰ cho list / default create / dashboard**, khÃ´ng dÃ¹ng lÃ m authorization cho detail/edit/preview
- Content Manager KHÃ”NG Ä‘Æ°á»£c chá»n global site â€” chá»‰ tháº¥y data gÃ¡n trá»±c tiáº¿p
- Detail/edit article hoáº·c content project thuá»™c domain khÃ¡c global: váº«n má»Ÿ náº¿u `canAccessSite` / policy cho phÃ©p; khÃ´ng 404 giáº£; khÃ´ng báº¯t buá»™c Ä‘á»•i header domain
- Cookie/session lÆ°u dÆ°á»›i key `seo_global_site_id`

**Account Owner Scoping:**
- `shouldScopeToAccountOwner()`: luÃ´n true, trá»« khi Admin Ä‘ang xem panel cá»§a connection khÃ¡c
- `accountOwnerId()`: Admin viewer â†’ `panelOwnerId()`; Manager/Staff â†’ `parent_id` (qua `User::accountOwnerId()`); Owner â†’ `auth()->id()`
- `accountSiteOwnerId()`: fallback vá» `accountOwnerId()`

**Content Project Scoping:**
- Content Manager: chá»‰ tháº¥y project cá»§a mÃ¬nh (`user_id == auth()->id()`)
- Planner/Manager: scope theo global site
- Cookie/session: `seo_global_content_project_id` + `seo_global_content_project_site_id`

### 1.5 SeoAccessControl Support Class

**File:** `app/Addons/SeoContentAi/Support/SeoAccessControl.php` (570 dÃ²ng)

ÄÃ¢y lÃ  class trung tÃ¢m cho toÃ n bá»™ phÃ¢n quyá»n SEO. CÃ¡c method chÃ­nh:

**Role check:**
```php
actualRole()           // seo_role thá»±c táº¿
effectiveRole()        // seo_role sau simulation
isContentManager()     // effectiveRole === content_manager
isPlanner()            // effectiveRole === planner
canAccessManagerFeatures()   // rank >= manager
canAccessPlannerFeatures()   // rank >= planner
canAccessContentFeatures()   // rank >= content_manager
```

**Site/Data scope:**
```php
globalSiteId()               // Tá»« cookie/session
setGlobalSiteId(?int)        // Set cookie + session
accountOwnerId()             // Resolve owner
accessibleSiteIds()          // Danh sÃ¡ch site IDs
canAccessSite(int)           // Kiá»ƒm tra site accessible
shouldApplyGlobalSiteScope() // CÃ³ Ä‘ang global site scope khÃ´ng
```

**Panel access:**
```php
canAccessSeoPanel(?User)     // Kiá»ƒm tra user Ä‘Æ°á»£c vÃ o SEO panel
isSeoPanelReadOnly()         // Admin viewer mode
guardSeoPanelMutation()      // abort_if read-only
canMutateInSeoPanel()        // !isSeoPanelReadOnly()
```

**Project-specific:**
```php
canAccessContentProjectRun(?SeoProject)  // Kiá»ƒm tra quyá»n xem run
canMutateContentProjects()               // canMutateInSeoPanel() + planner features
canRetryProjectRunItem(?SeoProject)      // Planner luÃ´n true; CM chá»‰ project cá»§a mÃ¬nh
```

---

## 2. Team Management

### 2.1 SeoTeam Page (`Filament/Pages/SeoTeam.php`)

- **Slug:** `/seo/{connection_hash}/seo/team`
- **Navigation:** "Team members" â†’ SEO Workspace
- **Permission:** `SeoAccessControl::canAccessManagerFeatures()` â€” chá»‰ Manager

**Table:**
| Column | Type | TÃ­nh nÄƒng |
|--------|------|-----------|
| `display_name` | TextColumn | Searchable, sortable, double-click edit nickname |
| `email` | TextColumn | Searchable, sortable, copyable |
| `seo_role` | SelectColumn | Dropdown: content_manager, planner, manager |
| `status` | TextColumn | Badge (banned/pending/normal) |
| `is_banned` | ToggleColumn | Block/unblock user |

**Actions:**
- **addMember** (header): Modal form â†’ email, pick existing user, name, password, seo_role
- **editNickname** (row): Sá»­a nickname qua `User::setMeta()`
- **removeFromTeam** (row): XoÃ¡ khá»i team (set parent_id=null, role=owner, seo_role=null, status=normal)

**Logic team:**
```php
teamMembersQuery(): User::where('parent_id', ownerId)->where('role', ROLE_STAFF)
assertCanManageMember(User $member): Guard mutation + kiá»ƒm tra member thuá»™c team
persistTeamMember(array $data): Táº¡o má»›i hoáº·c attach existing user
attachExistingMember(int $ownerId, User $existing): Kiá»ƒm tra conflict rá»“i update
```

### 2.2 Team Messages (`TeamMessageController`)

- **Route:** `/api/seo/team/*` â€” middleware `$seoTeamApiMiddleware` (giá»‘ng web API middleware nhÆ°ng **bá» CheckMainRole**)
- **Controller:** `Http/Controllers/TeamMessageController.php`

**Endpoints:**
| Method | Path | MÃ´ táº£ |
|--------|------|-------|
| GET | `/api/seo/team/config` | Config upload + `can_use_ai` |
| GET | `/api/seo/team/messages` | SSE stream tin nháº¯n má»›i (`after_id` / `last_id`); `?unread_summary=1` tráº£ JSON badge |
| POST | `/api/seo/team/messages` | Táº¡o message má»›i (text + file Ä‘Ã­nh kÃ¨m) |

### 2.5 Real-time transport (SSE)

Team chat **khÃ´ng cÃ²n HTTP polling** má»—i 4 giÃ¢y. Client dÃ¹ng `EventSource` tá»›i `GET /api/seo/team/messages?after_id={lastId}` (middleware session giá»¯ nguyÃªn).

**Server (`TeamMessageController::index`):**
- `?unread_summary=1` â†’ JSON `{ unread_count, latest_message_id, owner_id }` (badge launcher, khÃ´ng Ä‘á»•i).
- KhÃ´ng cÃ³ `unread_summary` â†’ `StreamedResponse` `text/event-stream`:
  - `after_id=0`: Ä‘áº©y tá»‘i Ä‘a 50 tin lá»‹ch sá»­ qua `data: {...}\n\n`, sau Ä‘Ã³ `event: history_end` kÃ¨m `config` / `can_use_ai`.
  - VÃ²ng láº·p: poll DB má»—i ~500ms, gá»­i tin `id > cursor`, heartbeat comment `:` má»—i 2 giÃ¢y.
  - Headers: `Cache-Control: no-cache`, `Connection: keep-alive`, `X-Accel-Buffering: no`.

**Client (`global-ai-chat.blade.php`):**
- `refreshTeamUnreadOnInit()` váº«n `fetch` vá»›i `unread_summary`.
- `EventSource(teamMessagesUrl + '?after_id=' + lastId)` thay `setInterval` polling.
- `onmessage` â†’ `handleIncomingTeamMessage()` (merge UI / badge / notification).
- `history_end` â†’ táº¯t loading, Ã¡p config khi má»Ÿ tab Team (`loadTeamMessages` reconnect `after_id=0`).
- `POST /api/seo/team/messages` (gá»­i tin) khÃ´ng Ä‘á»•i.

**File model:** `App\Models\TeamMessage` (table `team_messages`, connection `mysql`)
- Columns: `owner_id`, `user_id`, `message`, `attachment_path/name/mime/size`, timestamps
- Owner scope: lá»c theo `accountOwnerId()`

**Attachments:** Xá»­ lÃ½ qua `TeamChatAttachmentService`, validation errors â†’ 422

**Notifications:** `TeamChatNotificationService::notifyWorkspaceMembers()` sau khi táº¡o message

### 2.3 User Meta (`user_meta` table)

User cÃ³ EAV meta table Ä‘á»ƒ lÆ°u thÃ´ng tin má»Ÿ rá»™ng:
- `display_name` (nickname) â†’ lÆ°u qua `User::setMeta('nickname', ...)`
- CÃ¡c meta key khÃ¡c cÃ³ thá»ƒ má»Ÿ rá»™ng

---

## 3. SÆ¡ Ä‘á»“ luá»“ng phÃ¢n quyá»n

```mermaid
flowchart TB
    subgraph User["User Request"]
        REQ["HTTP Request"]
    end

    subgraph Auth["Authentication"]
        LOGIN["Filament Login<br/>/seo/login"]
        SESSION["Session Auth"]
        ROLE["users.role<br/>admin|owner|staff"]
    end

    subgraph SeoRole["SEO Role Resolution"]
        SEO["users.seo_role"]
        SIM["Simulation Session"]
        ACTUAL["actualRole()"]
        EFFECTIVE["effectiveRole()"]
        RANK["Rank: 1=CM, 2=PL, 3=MGR"]
    end

    subgraph Permissions["Permission Gates"]
        FEATURES["canAccessContentFeatures()"]
        PLANNER["canAccessPlannerFeatures()"]
        MANAGER["canAccessManagerFeatures()"]
        MUTATE["canMutateInSeoPanel()"]
        SITE_SCOPE["shouldApplyGlobalSiteScope()"]
        PROJECT["canAccessContentProjectRun()"]
    end

    subgraph Scope["Data Scoping"]
        OWNER_ID["accountOwnerId()"]
        SITE_ID["globalSiteId()"]
        PROJ_ID["globalContentProjectId()"]
        COOKIE["seo_global_site_id cookie"]
    end

    subgraph Admin["Admin Viewer Mode"]
        CONN_CTX["SeoConnectionContext"]
        IS_READONLY["isSeoPanelReadOnly()"]
    end

    REQ --> LOGIN
    LOGIN --> SESSION
    SESSION --> ROLE

    ROLE --> SEO
    SEO --> SIM
    SIM --> ACTUAL
    ACTUAL --> EFFECTIVE
    EFFECTIVE --> RANK

    ADMIN -->|"admin + xem connection khÃ¡c"| IS_READONLY
    RANK --> FEATURES
    RANK --> PLANNER
    RANK --> MANAGER
    IS_READONLY --> MUTATE

    COOKIE --> SITE_ID
    SITE_ID --> SITE_SCOPE
    SITE_SCOPE --> PROJ_ID
    SITE_SCOPE --> OWNER_ID
```

---

## HÆ°á»›ng dáº«n prompt â€” Team & Authorization

```
Access Control: Support/SeoAccessControl.php (570 dÃ²ng)
User Model: app/Models/User.php (constants: ROLE_*, SEO_ROLE_*, STATUS_*; hierarchy: parent_id/manager_id)
UserHierarchyService: app/Services/Users/UserHierarchyService.php
Admin Users: app/Filament/Resources/UserResource.php
Team Page: Filament/Pages/SeoTeam.php
Team Messages: Http/Controllers/TeamMessageController.php
Team Message Model: app/Models/TeamMessage.php (mysql)
User Meta Model: app/Models/UserMeta.php (mysql)
```
