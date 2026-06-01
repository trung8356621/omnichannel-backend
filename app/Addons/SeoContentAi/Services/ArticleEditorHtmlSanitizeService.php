<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Gỡ markup tạm của editor (highlight gợi ý, đánh dấu link sidebar, TipTap mark) trước lưu / đồng bộ WP.
 */
final class ArticleEditorHtmlSanitizeService
{
    public const LINK_MARK_CLASS = 'seo-editor-link-mark';

    public const LINK_SCROLL_LEGACY_CLASS = 'seo-link-scroll-highlight';

    public const EDITOR_LINK_CLASS = 'seo-editor-link';

    /** @var list<string> */
    private const TRANSIENT_CLASSES = [
        self::LINK_MARK_CLASS,
        self::LINK_SCROLL_LEGACY_CLASS,
    ];

    public function stripTransientEditorMarkup(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><div id="seo-sanitize-root">' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('seo-sanitize-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $this->unwrapTransientMarks($root);
        $this->stripTransientClasses($root);

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    private function unwrapTransientMarks(DOMElement $root): void
    {
        $marks = [];
        foreach ($root->getElementsByTagName('mark') as $mark) {
            if ($mark instanceof DOMElement) {
                $marks[] = $mark;
            }
        }

        foreach ($marks as $mark) {
            $this->unwrapNode($mark);
        }
    }

    private function stripTransientClasses(DOMElement $root): void
    {
        $walker = $root->getElementsByTagName('*');

        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($walker as $el) {
            if ($el instanceof DOMElement) {
                $elements[] = $el;
            }
        }

        foreach ($elements as $el) {
            if (! $this->elementHasTransientClass($el)) {
                continue;
            }

            if (strtolower($el->tagName) === 'a') {
                $this->removeTransientClassesFromElement($el);

                continue;
            }

            if (in_array(strtolower($el->tagName), ['mark', 'span'], true)) {
                $this->unwrapNode($el);

                continue;
            }

            $this->removeTransientClassesFromElement($el);
        }
    }

    private function elementHasTransientClass(DOMElement $el): bool
    {
        $class = (string) $el->getAttribute('class');
        if ($class === '') {
            return false;
        }

        foreach (self::TRANSIENT_CLASSES as $transient) {
            if (preg_match('/\b' . preg_quote($transient, '/') . '\b/', $class) === 1) {
                return true;
            }
        }

        return false;
    }

    private function removeTransientClassesFromElement(DOMElement $el): void
    {
        $classes = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter(
            $classes,
            static fn (string $c): bool => $c !== '' && ! in_array($c, self::TRANSIENT_CLASSES, true),
        ));

        if ($classes === []) {
            $el->removeAttribute('class');
        } else {
            $el->setAttribute('class', implode(' ', $classes));
        }
    }

    private function unwrapNode(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }

        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }

        $parent->removeChild($el);
    }
}
