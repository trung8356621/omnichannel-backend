# Keyword Clustering

`Services/KeywordIntelligence/KeywordClusterService.php` (existing — không rewrite trong Phase 1 tiếp theo).

## Input

- `SeoKeywordWorkspace`, strategy string: `balanced` | `tight` | `loose` (config `seo-content-ai.keyword_intelligence.clustering.default_strategy`, dùng khi handler không truyền `clustering_strategy` tường minh).
- Chạy trên toàn bộ `SeoKiKeyword` chưa `is_excluded` của workspace.

## Output — `SeoKeywordCluster`

Cột enrich (migration `2026_07_27_171000_enrich_keyword_intelligence_tables.php`):

| Cột | Ý nghĩa |
|---|---|
| `tenant_id`, `site_id` | Copy từ workspace để lọc trực tiếp không cần join |
| `description` | Tóm tắt cluster (nếu strategy sinh ra) |
| `funnel_stage` | Cast `KeywordFunnelStage` |
| `relevance_score`, `opportunity_score`, `priority_score` | Aggregate từ keyword con |
| `suggested_content_type` | `write_new` \| `rewrite` \| `improve` — mặc định `write_new` |
| `suggested_title`, `suggested_description` | Dùng khi convert sang Content Project ([xem](KEYWORD_TO_CONTENT_PROJECT.md)) |
| `target_article_ref` | `ContentProjectPublicRef::article()` — nếu set thì `suggested_content_type` **phải** là `rewrite`/`improve` |
| `preserve_manual_primary` | Không tự đổi `primary_keyword_id` khi đã set thủ công |

## Trạng thái (`KeywordClusterStatus`)

`draft → approved/excluded → converted`. Chuyển `approved`/`excluded` qua `ApproveKeywordClustersCommand` (Filament: nút Approve/Reject trong tab Clusters). Chỉ cluster `approved` mới convert được sang Content Project.

## Liên kết Topical Map

Sau khi cluster, `TopicalMapBuilder::build()` gán `cluster.topic_id` = pillar tương ứng theo `search_intent` và tạo `SeoTopicClusterLink` (`relationship = primary`). Xem [TOPICAL_MAP.md](TOPICAL_MAP.md).
