<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;

final class ArticleFaqHtmlRenderer
{
    /**
     * HTML accordion FAQ (không thêm tiêu đề H2 — giữ tiêu đề gốc trong bài).
     *
     * @return list<array{question: string, answer: string, more?: string}>
     */
    public function resolveFaqs(SeoArticle $article): array
    {
        return $article->resolveFaqs();
    }

    public function renderAccordionHtml(SeoArticle $article): string
    {
        $faqs = $this->resolveFaqs($article);
        if ($faqs === []) {
            return '';
        }

        $parts = ['<div class="omi-faq-container seo-article-preview-faq">'];

        foreach ($faqs as $faq) {
            $question = htmlspecialchars((string) ($faq['question'] ?? ''), ENT_QUOTES, 'UTF-8');
            $answerRaw = (string) ($faq['answer'] ?? '');
            $moreRaw = trim((string) ($faq['more'] ?? ''));
            $answerHtml = preg_match('/<[a-z][\s\S]*>/i', $answerRaw) === 1
                ? $answerRaw
                : nl2br(htmlspecialchars($answerRaw, ENT_QUOTES, 'UTF-8'), false);
            $moreHtml = '';
            if ($moreRaw !== '') {
                $moreHtml = preg_match('/<[a-z][\s\S]*>/i', $moreRaw) === 1
                    ? $moreRaw
                    : nl2br(htmlspecialchars($moreRaw, ENT_QUOTES, 'UTF-8'), false);
            }

            $parts[] = '<details class="omi-faq-item" open>';
            $parts[] = '<summary class="omi-faq-item__summary">' . $question . '</summary>';
            if ($moreHtml !== '') {
                $parts[] = '<div class="omi-faq-item__more">' . $moreHtml . '</div>';
            }
            $parts[] = '<div class="omi-faq-item__answer">' . $answerHtml . '</div>';
            $parts[] = '</details>';
        }

        $parts[] = '</div>';

        return implode("\n", $parts);
    }

    public function renderBodyWithFaqs(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        $faqHtml = $this->renderAccordionHtml($article);

        if ($body === '') {
            return $faqHtml;
        }

        if ($faqHtml === '') {
            return $body;
        }

        if (str_contains($body, '[omi_faq]')) {
            return str_replace('[omi_faq]', $faqHtml, $body);
        }

        if (preg_match('/<p[^>]*class="[^"]*omi-faq-placeholder[^"]*"[^>]*>\s*\[omi_faq\]\s*<\/p>/i', $body) === 1) {
            return preg_replace(
                '/<p[^>]*class="[^"]*omi-faq-placeholder[^"]*"[^>]*>\s*\[omi_faq\]\s*<\/p>/i',
                $faqHtml,
                $body,
                1,
            ) ?? $body;
        }

        return $body . "\n\n" . $faqHtml;
    }
}
