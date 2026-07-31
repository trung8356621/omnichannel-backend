> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Output Validation

Pipeline: raw â†’ normalize steps â†’ parse â†’ schema validate â†’ DTO.

Normalize (explicit only): `trim`, `strip_markdown_fence`, `strip_wrapping_quotes`, `first_non_empty_line`.

Failures: empty, invalid JSON, truncated, refused, marker leak (`[START`/`[END`) for types that set `reject_previous_step_markers`.

Types: text, markdown, **markdown_sections**, html, json, structured_object, list, score, classification.

## markdown_sections

Exceptional output type for prompts that intentionally return multiple task sections from **one** AI call.

Parser: `MarkdownSectionsOutputParser` â€” definition-driven (reads `output_schema.sections`). Escapes markers before regex. No hardcoded hook-key branches.

Rules:

- Match exact `start_marker` / `end_marker`
- Trim + optional fence strip per section `normalize`
- Reject missing required sections, duplicates, mismatched start/end, nested declared markers
- Reject undeclared task markers when `reject_unknown_task_markers` / strict mode
- Reject text outside sections when `allow_text_outside_sections=false`
- Section values exclude START/END markers; `total` port may preserve full raw (`combined_output.preserve_markers`)

Typed failures: `MissingRequiredSection`, `DuplicateOutputSection`, `MismatchedSectionMarker`, `UnknownSectionMarker`, `TextOutsideDeclaredSections`, `InvalidSectionOutput` â€” messages include hook key/version, section key, expected markers, `correlation_id`. Do not show full AI response / secrets in production UI.

Result shape:

```json
{
  "sections": { "outline": "...", "vocabulary": "..." },
  "ports": {
    "task_1_outline": "...",
    "task_2_vocabulary": "...",
    "total": "..."
  }
}
```

Currently used only by `article.outline.generate@0.1.0`. Do not migrate other prompts unless multi-section-in-one-call is intentional.

## Article markdown (generate / rewrite)

`article.content.generate` and `article.content.rewrite` use `type: markdown` with `minimum_length`, optional `reject_provider_preamble`, and `reject_task_markers=false` for generate. Normalize: `trim`, `strip_markdown_fence`. No Markdownâ†’HTML in Hook Runtime.

Retry: Hook runtime classifies validation failure only. Retry ownership remains PromptRunner / AiModelRouter â€” no second retry loop in the parser.
