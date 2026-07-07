<?php

declare(strict_types=1);

return [
    'heading' => 'Cần ít nhất 2 thẻ H2 trong bài viết.',
    'heading.pass' => 'Cấu trúc nội dung có đủ thẻ H2 (+:points).',

    'length' => 'Nội dung chưa đạt độ dài mục tiêu (:count/:target từ, 0 điểm).',
    'length.pass' => 'Độ dài nội dung đạt mục tiêu (:count/:target từ, +:points).',

    'image_ratio' => 'Không có ảnh hoặc tỷ lệ chữ/ảnh chưa hợp lý (lý tưởng 250–450 từ/ảnh). Thiếu ALT sẽ bị trừ điểm.',
    'image_ratio.pass' => 'Mật độ ảnh lý tưởng (:ratio từ/ảnh, +:points).',

    'wiki_trust' => 'Thiếu liên kết ngoài wiki-trust.',
    'wiki_trust.pass' => 'Có liên kết ngoài wiki-trust (+:points).',

    'faq_schema' => 'Thiếu FAQ schema (chưa có dữ liệu FAQ).',
    'faq_schema.pass' => 'Đã có dữ liệu FAQ schema (+:points).',

    'keyword_density' => 'Từ khóa chính chưa có trong tiêu đề, meta description, slug URL hoặc 100 từ đầu.',
    'keyword_density.pass' => 'Từ khóa chính có trong tiêu đề, meta, slug và 100 từ đầu (+:points).',

    'missing_focus_keyword' => 'Chưa gán từ khóa chính cho bài viết.',
];
