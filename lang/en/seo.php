<?php

declare(strict_types=1);

return [
    'heading' => 'Need at least 2 H2 headings in the article.',
    'heading.pass' => 'Content structure has sufficient H2 headings (+:points).',

    'length' => 'Content length below target (:count/:target words, 0 points).',
    'length.pass' => 'Content length meets target (:count/:target words, +:points).',

    'image_ratio' => 'No images or poor text-to-image ratio (ideal 250–450 words per image). Missing ALT tags reduce the score.',
    'image_ratio.pass' => 'Text-to-image ratio is ideal (:ratio words/image, +:points).',

    'wiki_trust' => 'Missing at least one outbound wiki-trust link.',
    'wiki_trust.pass' => 'Has a wiki-trust outbound link (+:points).',

    'faq_schema' => 'FAQ schema is missing (no FAQ data saved).',
    'faq_schema.pass' => 'FAQ schema data is present (+:points).',

    'keyword_density' => 'Focus keyword is missing from title, meta description, URL slug, or the first 100 words.',
    'keyword_density.pass' => 'Focus keyword appears in title, meta description, slug, and first 100 words (+:points).',

    'missing_focus_keyword' => 'No focus keyword assigned for this article.',
];
