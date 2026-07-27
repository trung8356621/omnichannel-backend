# Keyword Cannibalization Detection

`Services/KeywordIntelligence/KeywordCannibalizationService.php::detect(SeoKeywordWorkspace $workspace): array`

Trả mảng risk item, không ghi DB (đọc-only, gọi theo yêu cầu hoặc trong stage `DetectingCannibalization` của `KeywordWorkspaceAnalysisService`).

## Nguồn risk

1. **Keyword-level** — 1 keyword có ≥ `config('seo-content-ai.keyword_intelligence.cannibalization.multi_mapping_threshold')` (mặc định 2) `SeoKeywordArticleMapping` với `mapping_type = current_content` trỏ tới các `article_ref` khác nhau. Nhiều bài cùng target 1 keyword chính.
2. **Cluster-level** — Các keyword trong cùng `SeoKeywordCluster` có mapping tới ≥ 2 `article_ref` khác nhau (nội dung phân mảnh cho cùng chủ đề dù khác keyword).

## Risk item shape

```json
{
  "type": "keyword_multi_article",
  "keyword_ref": "kw_...",
  "keyword": "...",
  "cluster_ref": "kwc_...",
  "article_refs": ["a_...", "a_..."],
  "risk_level": "high",
  "recommended_action": "Pick one canonical article for this keyword; de-optimize or merge the others."
}
```

Cluster-level item dùng `type = cluster_multi_article` với `cluster_ref` + `cluster_name` thay cho `keyword_ref`/`keyword`.

`risk_level` (`riskLevelForCount`, dựa trên số `article_id` distinct trùng nhau):

| Số article trùng | risk_level |
|---|---|
| 2 (= threshold mặc định) | low |
| 3 | medium |
| 4 | high |
| ≥5 | critical |

`recommended_action` (`recommendedAction`) là chuỗi cố định theo `type` + `risk_level`: với keyword-level gợi ý chọn 1 bài canonical/hợp nhất/redirect; với cluster-level gợi ý tái cấu trúc quanh 1 pillar hoặc phân hoá lại targeting.

## Read / Agent

- `Application\KeywordIntelligenceReadService::listCannibalization(siteId, workspaceRef)`.
- Agent capability: `keyword_intelligence.get_cannibalization` (read-only, scope `content-project:read`), adapter `Agent\KeywordIntelligenceReadService::getCannibalization()`.
- Filament: tab "Cannibalization" trong `ViewKeywordWorkspace`.
