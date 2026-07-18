<?php

declare(strict_types=1);

return [
    'none' => 'No Hook',
    'experimental_badge' => 'Experimental',
    'experimental_warning' => 'This Hook is experimental version :version.',
    'execution_failed_title' => 'Prompt Hook failed',
    'hook_template_owns_prompt' => 'When a Hook is selected, template and output contract come from the Hook Definition. Markdown on the right is used only when no Hook is attached.',
    'hook_legacy_prompt_template_note' => 'This Hook manages the input/output contract and runtime. The current Prompt content remains the template sent to the AI.',
    'input_mapping_hint' => 'Map workflow variables → Hook inputs (same {{field}} name unless noted).',

    'article_title_suggestion' => [
        'label' => 'Article title suggestion',
        'description' => 'Create or improve a title from the focus keyword and current title.',

        'template' => <<<'PROMPT'
## Hook constraints — article title suggestion
- Return exactly one article title as plain text.
- Do not add explanations, prefixes (e.g. "Title:"), markdown, or quotes.
- Prefer including the focus keyword naturally: {{keyword}}
- If old_title is not null, treat it as context to improve — do not copy it blindly: {{old_title}}
- Keep the title within {{max_length}} characters when possible.
- preserve_meaning={{preserve_meaning}}
PROMPT,

        'settings' => [
            'max_length' => 'Maximum length',
            'preserve_meaning' => 'Preserve meaning of the current title',
        ],
    ],

    'article_meta_description_suggestion' => [
        'label' => 'SEO meta description suggestion',
        'description' => 'Create or improve a meta description from the title and current description.',

        'template' => <<<'PROMPT'
## Hook constraints — SEO meta description suggestion
- Return exactly one meta description paragraph as plain text.
- Do not add explanations, prefixes (e.g. "Meta description:"), markdown, or quotes.
- Base the description on the title: {{title}}
- If old_description is not null, use it as context to improve: {{old_description}}
- Target length between {{min_length}} and {{max_length}} characters.
- Do not invent specific facts that are not present in the input.
PROMPT,

        'settings' => [
            'min_length' => 'Minimum length',
            'max_length' => 'Maximum length',
        ],
    ],

    'article_outline_generate' => [
        'label' => 'Generate article outline',
        'description' => 'Generate an SEO outline / heading structure from keyword and context (experimental 0.1.0).',
    ],

    'article_content_generate' => [
        'label' => 'Write article',
        'description' => 'Generate a new article from outline, vocabulary, keyword and site context (experimental 0.1.0).',
    ],

    'article_content_rewrite' => [
        'label' => 'Rewrite article',
        'description' => 'Rewrite or improve existing content per instructions while preserving search intent and source facts (experimental 0.1.0).',
    ],

    'article_faq_generate' => [
        'label' => 'Generate article FAQ',
        'description' => 'Structured FAQ generation (experimental — vertical slice not completed).',
    ],

    'keyword_discovery_structured' => [
        'label' => 'Keyword discovery (JSON)',
        'description' => 'Structured keyword discovery (experimental — vertical slice not completed).',
    ],
];
