<?php

declare(strict_types=1);

return [
    'none' => 'Không sử dụng Hook',

    'article_title_suggestion' => [
        'label' => 'Gợi ý tiêu đề bài viết',
        'description' => 'Tạo hoặc cải thiện tiêu đề từ từ khóa chính và tiêu đề hiện tại.',

        'template' => <<<'PROMPT'
## Hook constraints — article title suggestion
- Return exactly one article title as plain text.
- Do not add explanations, prefixes (e.g. "Title:" / "Tiêu đề:"), markdown, or quotes.
- Prefer including the focus keyword naturally: {{keyword}}
- If old_title is not null, treat it as context to improve — do not copy it blindly: {{old_title}}
- Keep the title within {{max_length}} characters when possible.
- preserve_meaning={{preserve_meaning}}
PROMPT,

        'settings' => [
            'max_length' => 'Độ dài tối đa',
            'preserve_meaning' => 'Giữ ý nghĩa tiêu đề hiện tại',
        ],
    ],

    'article_meta_description_suggestion' => [
        'label' => 'Gợi ý thẻ mô tả SEO',
        'description' => 'Tạo hoặc cải thiện thẻ mô tả dựa trên tiêu đề và mô tả hiện tại.',

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
            'min_length' => 'Độ dài tối thiểu',
            'max_length' => 'Độ dài tối đa',
        ],
    ],
];
