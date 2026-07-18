<?php

declare(strict_types=1);

return [
    'none' => 'No Hook',

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
];
