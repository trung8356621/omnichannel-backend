# Architecture Decision Records — SeoContentAi / Content Project & Extension Platform

> Index: [../README.md](../README.md) · [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)

Mỗi ADR bất biến sau khi `Status: Accepted`. Muốn đổi hành vi đã khóa → ADR mới supersede, không sửa ngược ADR cũ.

---

## ADR-001 — Content Project là Aggregate Root

**Status:** Accepted

**Context:** Content Project (`SeoProject` + `SeoProjectTask` + `SeoArticle` liên kết) là đơn vị nghiệp vụ trung tâm của addon; nhiều actor (Filament, Agent, Queue, WordPress bridge) cùng ghi.

**Decision:** Content Project là aggregate root duy nhất cho vòng đời tạo/generate/schedule/publish/archive. Mọi thay đổi trạng thái đi qua boundary của aggregate (CommandBus), không actor nào ghi thẳng field lifecycle qua Eloquent ngoài boundary.

**Consequences:** Thêm actor mới (Agent, API v1) chỉ cần map sang Command hiện có, không tạo đường ghi riêng.

**Forbidden alternatives:** Tách Project/Task/Article thành aggregate độc lập tự ý ghi lifecycle chéo nhau; Filament page gọi thẳng `Model::update()` cho field lifecycle.

**Enforcement:** `ContentProjectCommandBusCutoverTest`, `ContentProjectApplicationApiFoundationTest`.

---

## ADR-002 — Mọi ghi dữ liệu qua CommandBus

**Status:** Accepted

**Context:** Trước cutover, Filament/Livewire/Job gọi thẳng Service method rải rác, khó audit và khó thêm actor (Agent, API).

**Decision:** `ContentProjectCommandBus` là cổng ghi duy nhất. Handler nhận `ContentProjectCommand` + `ActorContext`, trả `ContentProjectActionResult`. UI/Agent/API/Queue đều build Command rồi dispatch qua bus.

**Consequences:** Idempotency, tenant guard, business lock, audit log tập trung một chỗ (`AbstractPublishingHandler` và tương đương).

**Forbidden alternatives:** Gọi `Service->method()` ghi trực tiếp bỏ qua bus; `static::getModel()::create($data)` trong Filament pages cho lifecycle fields.

**Enforcement:** `ContentProjectCommandBusCutoverTest`.

---

## ADR-003 — Agent chỉ thấy Capability, không thấy Handler/Model

**Status:** Accepted

**Context:** Agent/MCP là actor bên ngoài core business logic, rủi ro cao nếu truy cập trực tiếp Eloquent hay Handler class.

**Decision:** `ContentProjectAgentGateway` chỉ nhận `capability` (string) + `input` (array), resolve qua `CanonicalCapabilityRegistry`, build Command qua `ContentProjectAgentCommandFactory`, dispatch qua CommandBus. Agent không có quyền truy cập Handler/Model class trực tiếp.

**Consequences:** Thêm/bớt capability cho Agent chỉ sửa registry + policy, không đổi Gateway.

**Forbidden alternatives:** Agent code import Model (`SeoProject`, `SeoProjectTask`) hay Handler class trực tiếp; Agent gọi Service method bỏ qua Gateway.

**Enforcement:** `ExtensionCutoverCapabilityAndEventsTest`, `ContentProjectAgentGatewayTest`, `ExtensionArchitectureFreezeTest`.

---

## ADR-004 — `SeoProjectRun` là chi tiết nội bộ

**Status:** Accepted

**Context:** Run/execution record phục vụ audit và điều phối pipeline execution, không phải public API / UX chính.

**Decision:**
- `SeoProjectRun` (và bảng execution liên quan) chỉ truy cập nội bộ trong Services / RunEngine.
- Public API/Agent chỉ thấy dữ liệu qua `operation_ref` / `get_operation`.
- **UI canonical:** Content Project → Project Items → Article (`ViewSeoProject` / `EditSeoProject`).
- **Không** còn lớp điều hướng Run History / Run Detail như UX chính. Route legacy `/{record}/runs`, `/runs/{run}`, `/runs/{run}/items/{article}` chỉ **redirect** về project workspace (hoặc 404 nếu không resolve được project).

**Consequences:** `/content-projects/{id}` = canonical operations workspace (một bảng items: generation + lifecycle + queue). Publishing Queue chỉ còn compatibility redirect + filter query. Semantic status badges (`ContentProjectStatusBadgePresenter`). Generate pending chỉ never-generated. Không phục hồi tầng Run History.

**Forbidden alternatives:** Trả `run_id` số nguyên thô qua Agent/API; Agent query bảng run; phục hồi Run History như hub UI chính.

**Enforcement:** `ContentProjectApplicationApiFoundationTest`; Filament redirect stubs `ListSeoProjectRuns` / `ViewSeoProjectRun` / `ViewSeoProjectRunStep`.

---

## ADR-005 — Archive = Destroy AI Workspace

**Status:** Accepted

**Context:** Archive cần giải phóng tài nguyên (workspace AI, prompt history, execution records, local media) trong khi vẫn giữ business record.

**Decision:** Archive Content Project luôn destroy toàn bộ AI Workspace (Prompt History, Execution records, Local media, SaaS revisions) đồng thời với việc archive business metadata. Đây là hành vi mặc định, không tùy chọn giữ lại workspace.

**Consequences:** Archive là thao tác không đảo ngược đối với workspace — cần confirmation token trước khi thực thi (xem Gateway `issueConfirmationPreview`).

**Forbidden alternatives:** Archive "soft" giữ nguyên workspace rồi dọn sau; archive không cảnh báo `destructive_effects` cho Agent.

**Enforcement:** `ContentProjectWorkspaceDestroyArchitectureTest`, `ArchiveContentProjectServiceTest`.

---

## ADR-006 — Restore không khôi phục workspace cũ

**Status:** Accepted

**Context:** Sau Archive (ADR-005), workspace AI đã bị destroy vĩnh viễn.

**Decision:** Restore Content Project chỉ khôi phục business metadata (project, task, article) về trạng thái active; **không** khôi phục lại AI Workspace, Prompt History, hay Execution records đã bị destroy. Project sau Restore coi như khởi động lại pipeline từ đầu nếu cần generate lại.

**Consequences:** User được thông báo rõ workspace phải chạy lại từ đầu sau Restore.

**Forbidden alternatives:** Restore cố gắng phục hồi execution/prompt history từ backup ẩn; Restore trả kết quả ngụ ý workspace cũ vẫn còn.

**Enforcement:** `ContentProjectArchiveRestoreTest`.

---

## ADR-007 — SaaS (core) sở hữu lịch publish

**Status:** Accepted

**Context:** Có hai nguồn có thể set lịch publish: SaaS core (Content Project scheduler) và WordPress (qua plugin bridge).

**Decision:** `scheduled_publish_at` và toàn bộ logic lập lịch publish thuộc quyền sở hữu của SaaS/Content Project core. WordPress side không được ghi đè lịch publish qua sync thông thường.

**Consequences:** Đổi lịch publish luôn qua Command (`MoveProjectItemScheduleHandler`, …), WordPress chỉ nhận kết quả publish, không phải nguồn set lịch.

**Forbidden alternatives:** `ContentProjectWorkspaceSaveService` set `'scheduled_publish_at' => $article->published_at` (đồng bộ ngược từ WP); Manual Sync tự ý sửa lịch.

**Enforcement:** `ContentProjectCommandBusCutoverTest::test_workspace_save_does_not_touch_publish_schedule`.

---

## ADR-008 — Manual Sync chỉ chạm workspace, không chạm lịch publish

**Status:** Accepted

**Context:** `ContentProjectWorkspaceSaveService` / Manual Sync đồng bộ nội dung bài viết (workspace) giữa editor và storage.

**Decision:** Manual Sync chỉ cập nhật `last_synced_at` và các field nội dung workspace; không được set/đổi `scheduled_publish_at` hay bất kỳ field lifecycle publish nào (xem ADR-007).

**Consequences:** Tách rõ trách nhiệm: Sync = nội dung, Schedule Command = lịch.

**Forbidden alternatives:** Sync tự động suy ra lịch publish từ `published_at` của WordPress rồi ghi ngược.

**Enforcement:** `ContentProjectCommandBusCutoverTest::test_workspace_save_does_not_touch_publish_schedule`.

---

## ADR-009 — Publish tối thiểu at-least-once, idempotent theo external reference

**Status:** Accepted

**Context:** Network/timeout giữa SaaS và WordPress có thể khiến publish request lặp lại hoặc mất phản hồi.

**Decision:** Publish pipeline đảm bảo **at-least-once delivery**; idempotency đạt được bằng cách reconcile theo `external_reference` / `wp_post_id` trước khi tạo bài mới (xem `WordPressPublisher::findByExternalReference`). Publish handler dùng `idempotency_key` theo item + actor.

**Consequences:** Retry an toàn không tạo duplicate post; publish có thể chạy nhiều lần với cùng kết quả cuối.

**Forbidden alternatives:** Publish "exactly-once" giả định network không lỗi; tạo bài mới khi không tìm thấy phản hồi thay vì reconcile.

**Enforcement:** `ContentProjectPublishingLifecyclePolishTest`, `ExtensionArchitectureFreezeTest` (kiểm tra handler dùng `PublisherResolver`).

---

## ADR-010 — Public reference luôn dùng prefix `cp_` / `cpi_`, không lộ ID nội bộ

**Status:** Accepted

**Context:** Agent/API v1 là bề mặt public, ID số nguyên nội bộ (`SeoProject::id`) không nên lộ ra ngoài trực tiếp.

**Decision:** Mọi reference trả ra Agent/API dùng định dạng public (`cp_{id}` cho project, `cpi_{id}` cho item) qua `ContentProjectPublicRef`. Input từ bên ngoài cũng phải qua resolver này để convert ngược, không nhận raw numeric ID.

**Consequences:** Agent Policy có thể chặn cứng numeric ID thô (`assertNoNumericIds`).

**Forbidden alternatives:** Trả `project_id: 123` thô trong response Agent; nhận input `project_id` numeric trực tiếp từ Agent capability.

**Enforcement:** `ContentProjectApplicationApiFoundationTest`, `ContentProjectAgentGatewayTest`.

---

## ADR-011 — Tenant guard fail-closed

**Status:** Accepted

**Context:** Content Project multi-tenant theo site; sai sót tenant scoping có thể lộ dữ liệu chéo site.

**Decision:** Mọi truy cập (trừ actor `queue` nội bộ) phải qua `ContentProjectTenantGuard::assertCanAccessProject()` / tương đương trước khi đọc/ghi. Không rõ tenant → từ chối (fail-closed), không mặc định cho phép.

**Consequences:** Thêm actor mới bắt buộc implement tenant check trước khi cấp quyền.

**Forbidden alternatives:** Bỏ qua tenant check khi "chưa chắc chắn"; mặc định `allow` khi thiếu context tenant.

**Enforcement:** `ContentProjectAgentGatewayTest`, `SeoAccessControlAccountOwnerTest`.

---

## ADR-012 — Core chỉ phụ thuộc contracts/registries, không phụ thuộc Extension cụ thể

**Status:** Accepted

**Context:** Extension Cutover đưa AI Provider, Publisher, Pipeline, SEO Provider ra khỏi core thành plugin có thể thay thế.

**Decision:** `Application/Handlers`, Agent layer, và core Services chỉ được import **Contracts** (`ContentPublisher`, `AiTextProviderInterface`, `PipelineDefinitionInterface`, `SeoProviderInterface`, `CapabilityContributor`) và **Registries/Resolvers** (`PublisherResolver`, `AiProviderResolver`, `PipelineResolver`, `CanonicalCapabilityRegistry`). Core không được import class cụ thể dưới `Extension\Builtin\*`.

**Consequences:** Thay thế WordPress bằng Ghost/Shopify hay Gemini bằng OpenAI không đổi core.

**Forbidden alternatives:** `Application/Handlers/*` import `Extension\Builtin\Wordpress\WordPressPublisher` trực tiếp; Agent import class dưới `Extension\Builtin`.

**Enforcement:** `ExtensionArchitectureFreezeTest`.

---

## ADR-013 — Extension không có lifecycle hook ngoài register/boot

**Status:** Accepted

**Context:** Extension chạy trong cùng process với core (không sandbox), cần giới hạn bề mặt can thiệp.

**Decision:** `ExtensionProvider` chỉ có 2 lifecycle hook: `register(ExtensionContext $ctx)` (đăng ký driver vào registry) và `boot(ExtensionContext $ctx)` (subscribe event / warm cache). Không có hook can thiệp vào request lifecycle của Laravel (middleware toàn cục, service provider override, v.v.) ngoài 2 hàm này.

**Consequences:** Extension không thể thay đổi hành vi core ngoài phạm vi registry mà nó đăng ký.

**Forbidden alternatives:** Extension đăng ký middleware global; Extension override binding core trong container; Extension chạy code ở `boot()` ảnh hưởng request khác ngoài phạm vi của chính nó.

**Enforcement:** `ExtensionSdkFoundationTest`, review thủ công `ExtensionProvider` implementations.

---

## ADR-014 — WordPress là builtin extension, không phải core hard-code

**Status:** Accepted

**Context:** WordPress từng là publisher duy nhất hard-code trong Application layer.

**Decision:** WordPress publisher sống dưới `Extension/Builtin/Wordpress/` (`WordPressPublisher` implements `ContentPublisher`), đăng ký qua `WordpressExtensionProvider` vào `ContentPublisherRegistry` + `PublisherRegistry`. Application chỉ resolve qua `PublisherResolver`, coi WordPress như bất kỳ publisher nào khác.

**Consequences:** WordPress không có đặc quyền core; publisher mới (Ghost, Shopify) theo đúng pattern tương tự không cần sửa Application.

**Forbidden alternatives:** Application/Handlers import thẳng `WordPressPublisher`/`WordPressContentPublisher`; giữ file `WordPressContentPublisher.php` trong `Application/Publishing`.

**Enforcement:** `ExtensionArchitectureFreezeTest`, [../modules/EXTENSION_SDK.md](../modules/EXTENSION_SDK.md).

---

## ADR-015 — Capability name bất biến sau khi publish

**Status:** Accepted

**Context:** Agent/MCP client và Automation Policy tham chiếu capability theo tên chuỗi (`content_project.generate`, …); đổi tên phá vỡ integration đang chạy.

**Decision:** Tên capability đã publish (core hoặc extension) là bất biến. Đổi hành vi capability không đổi tên; cần capability mới thì thêm tên mới, deprecate tên cũ dần (không xóa đột ngột). Core capability prefix `content_project.` được bảo vệ — extension không được đăng ký capability trùng prefix này.

**Consequences:** Danh sách `CanonicalCapabilityRegistry::conflicts()` phải rỗng ở trạng thái ổn định.

**Forbidden alternatives:** Rename capability tại chỗ; extension đăng ký `content_project.*` capability riêng để override core.

**Enforcement:** `ExtensionCutoverCapabilityAndEventsTest`, `config/seo_architecture.php` (`core_capabilities_protected_prefix`).

---

## ADR-016 — Extension event có version, isolate lỗi từng listener

**Status:** Accepted

**Context:** Extension subscribe event nội bộ (`ExtensionEventBus`); một listener lỗi không được làm hỏng domain flow hay các listener khác.

**Decision:** Mọi event tên đều có suffix version (`.v1`, ví dụ `content_project.created.v1`). `ExtensionEventBus::dispatch()` bọc từng listener trong try/catch riêng — lỗi 1 listener không ảnh hưởng listener khác hay domain path chính (`ContentProjectDomainEvents` không throw ra ngoài).

**Consequences:** Thêm field mới vào payload không cần bump version nếu additive; đổi shape breaking phải bump `.v2` và giữ `.v1` cho consumer cũ nếu cần.

**Forbidden alternatives:** Event name không version; listener lỗi làm crash toàn bộ dispatch hoặc rollback domain transaction.

**Enforcement:** `ExtensionCutoverCapabilityAndEventsTest`, `ExtensionSdkFoundationTest`.

---

## ADR-017 — Architecture Freeze v1.0

**Status:** Accepted

**Context:** ADR-001..016 định hình xong Extension Cutover; cần khóa surface public để tránh regression âm thầm.

**Decision:** Kể từ ngày freeze (xem [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)), các public contract (Publisher, AI Text/Image Provider, Pipeline Definition, SEO Provider, Capability, Extension Events v1) không được thay đổi breaking mà không có ADR mới. SDK major version giữ nguyên = 1 cho tới khi có ADR supersede rõ ràng.

**Consequences:** Mọi PR đổi breaking 1 trong các contract trên phải kèm ADR mới + bump SDK version.

**Forbidden alternatives:** Sửa signature contract public trực tiếp không qua ADR; bump `SdkVersion::MAJOR` mà không có ADR tương ứng.

**Enforcement:** `ExtensionArchitectureFreezeTest`, `ExtensionSdkFoundationTest`, [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md).
