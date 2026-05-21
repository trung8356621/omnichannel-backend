<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Chuyển Markdown cơ bản sang HTML (đủ cho dàn ý / thân bài thử nghiệm).
 */
final class SimpleMarkdownHtmlConverter
{
    public function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($markdown)) ?: [];
        $html = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }

                continue;
            }

            if (preg_match('/^#\s+(.+)$/u', $trimmed, $matches) === 1) {
                $this->closeList($html, $inList);
                $inList = false;
                $html[] = '<h1>' . e($matches[1]) . '</h1>';

                continue;
            }

            if (preg_match('/^##\s+(.+)$/u', $trimmed, $matches) === 1) {
                $this->closeList($html, $inList);
                $inList = false;
                $html[] = '<h2>' . e($matches[1]) . '</h2>';

                continue;
            }

            if (preg_match('/^###\s+(.+)$/u', $trimmed, $matches) === 1) {
                $this->closeList($html, $inList);
                $inList = false;
                $html[] = '<h3>' . e($matches[1]) . '</h3>';

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                if (! $inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . e($matches[1]) . '</li>';

                continue;
            }

            $this->closeList($html, $inList);
            $inList = false;
            $html[] = '<p>' . e($trimmed) . '</p>';
        }

        $this->closeList($html, $inList);

        return implode("\n", $html);
    }

    /**
     * @param  list<string>  $html
     */
    private function closeList(array &$html, bool &$inList): void
    {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    }
}
