<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Support\SimpleMarkdownHtmlConverter;

/**
 * Cắt FAQ khỏi Markdown/HTML bài viết và gắn shortcode [omi_faq].
 */
final class ArticleContentFaqService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly SimpleMarkdownHtmlConverter $markdownHtml,
        private readonly ArticlePostContentFaqPlaceholder $postContentPlaceholder,
    ) {
    }

    /**
     * Thay đoạn FAQ đã chọn trong HTML bài viết bằng bản đã cắt (giữ tiêu đề + placeholder).
     */
    public function replaceFaqFragmentInArticleHtml(string $articleHtml, string $fragmentHtml, string $strippedFragmentHtml): string
    {
        $articleHtml = trim($articleHtml);
        $fragmentHtml = trim($fragmentHtml);
        $strippedFragmentHtml = trim($strippedFragmentHtml);

        if ($articleHtml === '' || $strippedFragmentHtml === '') {
            return $articleHtml;
        }

        if ($fragmentHtml !== '' && str_contains($articleHtml, $fragmentHtml)) {
            return substr_replace($articleHtml, $strippedFragmentHtml, strpos($articleHtml, $fragmentHtml), strlen($fragmentHtml));
        }

        return $this->workflowParser->stripFaqContentKeepHeadingHtml($articleHtml, false);
    }

    public function persistArticleBodyHtml(SeoArticle $article, string $html): void
    {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        $article->update([
            'body' => $html,
        ]);

        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_post_content'],
            ['meta_value' => $html],
        );
    }

    public function normalizeHtmlForWordPress(string $html): string
    {
        return $this->postContentPlaceholder->normalizeForWordPress($html);
    }

    public function stripFaqAndAppendShortcode(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        if (str_contains($markdown, '[omi_faq]')) {
            return $markdown;
        }

        return $this->workflowParser->removeFaqAndAppendShortcode($markdown);
    }

    private function ensureEditorPlaceholderMarkup(string $html): string
    {
        $token = WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;

        if (! str_contains($html, $token)) {
            return $html;
        }

        if (str_contains($html, 'omi-faq-placeholder')) {
            return $html;
        }

        return (string) preg_replace(
            '/\s*' . preg_quote($token, '/') . '\s*/u',
            $this->workflowParser->faqPlaceholderHtml(),
            $html,
            1,
        );
    }

    /**
     * Lưu nội dung đã cắt FAQ vào body + meta wp_post_content (chỉ Laravel; đồng bộ WP qua nút «Đồng bộ»).
     */
    public function applyStrippedContentToArticle(SeoArticle $article, string $markdown): void
    {
        $cleaned = $this->stripFaqAndAppendShortcode($markdown);
        if ($cleaned === '') {
            return;
        }

        $html = $this->markdownHtml->toHtml($cleaned);
        $html = $this->ensureEditorPlaceholderMarkup($html);

        $this->persistArticleBodyHtml($article, $html);
    }
}
