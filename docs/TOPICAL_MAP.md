# Topical Map

`Services/KeywordIntelligence/TopicalMapBuilder.php` — xây cây topic tối giản cho một workspace.

## Cấu trúc

```
Root (SeoKiTopic, topic_type=root, depth=0)
 └─ Pillar theo search_intent bucket (topic_type=pillar, depth=1)
     └─ SeoKeywordCluster (gắn qua SeoTopicClusterLink, relationship=primary)
```

`max_depth` lấy từ config `seo-content-ai.keyword_intelligence.topical_map.max_depth` (mặc định 3) — Phase 1 chỉ dùng tối đa depth 1 cho pillar, chưa sinh sub-topic sâu hơn.

## `TopicalMapBuilder::build(SeoKeywordWorkspace $workspace, ?int $actorId = null): SeoTopicalMapVersion`

1. `upsertRoot()` — 1 topic root / workspace (`slug = Str::slug(workspace.name)`).
2. Group toàn bộ `SeoKeywordCluster` của workspace theo `search_intent` (fallback `unknown`).
3. `upsertPillar()` mỗi nhóm — tên `"{Intent} Intent"`, tổng hợp `keyword_count`/`cluster_count`/`total_search_volume` từ các cluster trong nhóm.
4. `linkClusterToPillar()` — tạo `SeoTopicClusterLink` (unique `topic_id`+`cluster_id`) nếu chưa có; set `cluster.topic_id = pillar.id`.
5. `persistVersion()` — tăng `version` (unique theo `workspace_id`+`version`), lưu snapshot compact (chỉ refs + tên + số liệu, **không** lưu keyword thô) vào `SeoTopicalMapVersion.snapshot`, và `summary` (pillar_count/cluster_count/total_search_volume).

## Snapshot shape

```json
{
  "root": { "topic_ref": "kwt_...", "name": "My Workspace" },
  "pillars": [
    {
      "topic_ref": "kwt_...",
      "name": "Commercial Intent",
      "keyword_count": 42,
      "cluster_count": 6,
      "total_search_volume": 12000
    }
  ]
}
```

## Trigger

- `BuildTopicalMapCommand` (Filament: nút "Build topical map" trong `ViewKeywordWorkspace`).
- Tự động chạy trong `KeywordWorkspaceAnalysisService::analyze()` ở stage `BuildingTopics` (sau clustering, trước detecting cannibalization).

## Read

`KeywordIntelligenceReadService::getTopicalMap(siteId, workspaceRef)` trả version mới nhất (`orderByDesc('version')`) hoặc `null` nếu chưa build lần nào. Agent capability: `keyword_intelligence.get_topical_map`.
