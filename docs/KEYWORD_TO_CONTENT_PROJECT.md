# Keyword → Content Project Conversion

`Services/KeywordIntelligence/KeywordToContentProjectConverter.php` — chuyển các `SeoKeywordCluster` đã `approved` thành một `ContentProject` (aggregate riêng, không thuộc Keyword Intelligence).

## Điều kiện eligible

Một cluster chỉ convert được khi:

1. `status === approved` (set qua `ApproveKeywordClustersCommand`).
2. `resolveContentType()` không trả lỗi:
   - Nếu `target_article_ref` rỗng → luôn `write_new`.
   - Nếu `target_article_ref` có set → `suggested_content_type` **phải** là `rewrite` hoặc `improve` tường minh (không tự suy luận), nếu không sẽ bị loại kèm warning.

Cluster không đạt điều kiện xuất hiện trong `warnings[]` của preview/convert, không chặn các cluster hợp lệ khác trong cùng batch.

## `preview(SeoKeywordWorkspace $workspace, list<string> $clusterRefs): array`

Đọc-only — không ghi DB. Trả:

```json
{
  "total_clusters": 5,
  "eligible_clusters": 4,
  "total_keywords": 37,
  "items": [
    { "cluster_ref": "kwc_...", "name": "...", "status": "approved", "keyword_count": 8, "search_intent": "commercial", "content_type": "write_new", "target_article_ref": null, "eligible": true }
  ],
  "warnings": ["kwc_xxx: cluster status must be approved (current: draft)."],
  "quota_exceeded": false,
  "requires_confirmation": false
}
```

`requires_confirmation` = `KeywordIntelligenceQuotaGuard::requiresConfirmation(clusterCount)` (mặc định > 10 cluster).

## `convert(SeoKeywordWorkspace, list<string> $clusterRefs, ActorContext, ContentProjectCommandBus, array $projectAttributes = []): ContentProjectActionResult`

1. Lọc lại cluster eligible (double-check tại thời điểm convert, không tin preview cũ).
2. Build `tasksData` — mỗi row: `type` (`create` cho `write_new`, hoặc `rewrite`/`improve`), `keyword` (primary keyword hoặc tên cluster), `title` (`suggested_title` hoặc keyword), `description` (`suggested_description`). **Không** set `gallery_description` — trường này không thuộc phạm vi Keyword Intelligence.
3. Nếu có `target_article_ref` hợp lệ (giải mã qua `ContentProjectPublicRef::decodeArticle()`), thêm `source_content` = tiêu đề `SeoArticle` hiện có.
4. Dispatch `CreateContentProjectCommand($attributes, $tasksData)` qua `$bus->dispatch()` — `attributes.site_id` luôn ép về `workspace.site_id` (không tin `projectAttributes.site_id` từ caller).
5. Nếu tạo project thành công: set từng cluster `status = converted`, `content_project_ref`, `converted_at = now()`.
6. Trả `ContentProjectActionResult::ok(CONTENT_PROJECT_CREATED, ..., metadata: [ki_workspace_ref, ki_cluster_refs, ki_keyword_refs, ki_map_version_ref, ...project metadata])` — refs Keyword Intelligence lưu trong `metadata`, không đụng `projectId`/`affectedItemIds` (dành cho Content Project entities).

## Idempotency & Confirmation

- `PreviewContentProjectFromClustersCommand` → `PreviewContentProjectFromClustersHandler`: luôn dry-run, phát hành `confirmation_token` (`ContentProjectPreviewToken`) nếu `requiresConfirmation()` đúng (actor `agent`/`api`, hoặc vượt `convert_confirmation_threshold`).
- `CreateContentProjectFromKeywordClustersCommand` → `CreateContentProjectFromKeywordClustersHandler`: nếu `dryRun=true` trả preview không ghi DB; nếu cần confirmation mà thiếu/token không khớp fingerprint (`action`, `workspace_id`, `cluster_refs`, `project_attributes`) → `KeywordIntelligenceActionCodes::CONFIRMATION_REQUIRED`. Token được `consumeConfirmationToken()` sau khi convert thành công (one-shot).
- Actor `user` (Filament) mặc định **không** cần confirmation trừ khi vượt threshold — token chỉ bắt buộc cho `agent`/`api` hoặc batch lớn.

## Workspace archive ≠ Content Project Destroy Workspace

Archive một `SeoKeywordWorkspace` (`ArchiveKeywordWorkspaceCommand`) chỉ đóng băng workspace Keyword Intelligence (chặn import/analyze/convert mới, dữ liệu vẫn đọc được) — **không** liên quan đến "Destroy Workspace" khi archive một `ContentProject` (dọn AI workspace artifacts của Content Project, xem `docs/CONTENT_PROJECT_AGENT_APPROVALS.md`). Hai khái niệm độc lập, không dùng chung state hay hành vi.

## Filament

Tab "Clusters" trong `ViewKeywordWorkspace`: chọn cluster (checkbox) → "Preview convert" hiển thị `eligible_clusters`/`warnings`/`confirmation_token` (nếu có) → "Convert to Content Project" gửi lại `confirmation_token` đã lưu trong Livewire state. Cả hai action dispatch qua `app(ContentProjectCommandBus::class)->dispatch(...)`, không tạo `SeoProject` trực tiếp từ Filament.

## Agent / MCP

Write capabilities: `keyword_intelligence.preview_convert`, `keyword_intelligence.convert_to_content_project` (risk `write`, `confirmation_requirement=true` cho convert thật). Không có read capability riêng — kết quả convert trả qua `ContentProjectActionResult` như mọi command khác.
