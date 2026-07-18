<?php

declare(strict_types=1);

return [
    'none' => 'Không sử dụng Hook',
    'experimental_badge' => 'Thử nghiệm',
    'experimental_warning' => 'Hook đang ở phiên bản thử nghiệm :version.',
    'execution_failed_title' => 'Prompt Hook thất bại',
    'hook_template_owns_prompt' => 'Khi chọn Hook, template + output contract do Hook Definition quản lý. Nội dung Markdown bên phải chỉ dùng khi không gắn Hook.',
    'hook_legacy_prompt_template_note' => 'Hook quản lý contract input/output và runtime. Nội dung Prompt hiện tại vẫn là template gửi đến AI.',
    'input_mapping_hint' => 'Map biến workflow → input Hook (cùng tên {{field}} trừ khi ghi chú khác).',

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

    'article_outline_generate' => [
        'label' => 'Tạo dàn ý bài viết',
        'description' => 'Sinh dàn ý / cấu trúc heading SEO từ từ khóa và ngữ cảnh (thử nghiệm 0.1.0).',
    ],

    'article_content_generate' => [
        'label' => 'Viết bài viết',
        'description' => 'Tạo bài viết mới từ outline, vocabulary, keyword và ngữ cảnh site (thử nghiệm 0.1.0).',
    ],

    'article_content_rewrite' => [
        'label' => 'Viết lại bài viết',
        'description' => 'Viết lại / cải thiện bài hiện có theo yêu cầu, giữ search intent và dữ kiện nguồn (thử nghiệm 0.1.0).',
    ],

    'article_faq_generate' => [
        'label' => 'Tạo FAQ bài viết',
        'description' => 'Sinh FAQ có cấu trúc (thử nghiệm — chưa hoàn thiện vertical slice).',
    ],

    'keyword_discovery_structured' => [
        'label' => 'Khám phá từ khóa (JSON)',
        'description' => 'Sinh danh sách từ khóa có cấu trúc (thử nghiệm — chưa hoàn thiện vertical slice).',
    ],
];
