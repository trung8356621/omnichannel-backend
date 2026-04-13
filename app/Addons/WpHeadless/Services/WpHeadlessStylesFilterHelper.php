<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Services;

final class WpHeadlessStylesFilterHelper
{
    /** Thẻ HTML thuần (selector không chỉ định class/id) luôn giữ cho global. */
    private const HTML_TAGS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'body', 'br', 'button',
        'canvas', 'caption', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details', 'dfn',
        'dialog', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label',
        'legend', 'li', 'link', 'main', 'map', 'mark', 'meta', 'meter', 'nav', 'noscript', 'object', 'ol', 'optgroup',
        'option', 'output', 'p', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'section', 'select',
        'slot', 'small', 'source', 'span', 'strong', 'style', 'sub', 'summary', 'sup', 'svg', 'table', 'tbody', 'td',
        'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'u', 'ul', 'var', 'video', 'wbr',
    ];

    /** Danh sách ID selector loại bỏ khỏi CSS tối ưu vì đã xử lý bằng component Next.js. */
    private const EXCLUDED_ID_SELECTORS = [
        'reviews', 'commentform', 'review_form', 'ez-toc-container'
    ];

    /**
     * ID layout WordPress/theme thường không có trong template UX nhưng cần giữ rule (ví dụ #secondary .widget-title).
     *
     * @var list<string>
     */
    private const ALWAYS_ALLOWED_IDS = [
        'secondary', 'main', 'wrapper', 'content', 'masthead', 'colophon', 'primary',
    ];

    /**
     * Selector chứa bất kỳ chuỗi này → không purge (giữ rule cho Flickity/Flatsome slider trên Next).
     *
     * @var list<string>
     */
    private const PROTECTED_SLIDER_KEYWORDS = [
        'flickity', 'slider', 'slide', 'is-selected', 'is-dragging', 'dot', 'next', 'previous',
    ];

    /**
     * Phân tách, lọc file CSS, chia làm các classBlocks chứa logic giao diện và specialBlocks chứa @rule/html attributes.
     *
     * @param bool $isGlobal true = giữ :root, *, thẻ HTML thuần (vào specialBlocks). false = bỏ hẳn các khối đó (chỉ class/id + @font-face|keyframes|import…).
     */
    public static function filterCssByClassesAndIds(
        string $css,
        array $classes,
        array $ids,
        bool $stripDarkMode = false,
        array &$existingSignatures = [],
        bool $isGlobal = true
    ): array {
        $blocks = self::extractCssBlocks($css);
        $classBlocks = [];
        $specialBlocks = [];

        foreach ($blocks as $block) {
            $selector = $block['selector'];
            $body = $block['body'];
            $full = $block['full'];
            $selTrim = trim($selector);

            if ($stripDarkMode && self::selectorContainsDarkMode($selector)) {
                $filteredSelector = self::filterSelectorsRemoveDark($selector);
                if ($filteredSelector === '') {
                    continue;
                }
                $selector = $filteredSelector;
                $full = $filteredSelector . '{' . $body . '}';
                $selTrim = trim($selector);
            }

            if (str_starts_with($selTrim, '@')) {
                if (preg_match('/^@(?:font-face|(?:-\w+-)?keyframes|charset|import|layer)\b/i', $selTrim)) {
                    if (preg_match('/^@font-face\b/i', $selTrim) && preg_match('/fl-icons/i', $full)) {
                        continue;
                    }
                    $specialBlocks[] = $full;
                } elseif (preg_match('/^@(?:media|supports)\b/i', $selTrim)) {
                    if ($stripDarkMode && self::selectorContainsDarkMode($body)) {
                        $filteredBody = self::stripDarkModeFromCss($body);
                        if ($filteredBody !== '') {
                            $full = $selector . '{' . $filteredBody . '}';
                            $body = $filteredBody;
                        } else {
                            continue;
                        }
                    }
                    if (preg_match('/@(?:-\w+-)?keyframes\b/i', $body)) {
                        $specialBlocks[] = $full;
                    } else {
                        $filteredBody = self::filterCssBodyToAllowedClassesAndIds($body, $classes, $ids, $isGlobal);
                        if ($filteredBody !== '') {
                            $classBlocks[] = $selector . '{' . $filteredBody . '}';
                        }
                    }
                } else {
                    $filteredBody = self::filterCssBodyToAllowedClassesAndIds($body, $classes, $ids, $isGlobal);
                    if ($filteredBody !== '') {
                        $classBlocks[] = $selector . '{' . $filteredBody . '}';
                    }
                }
                continue;
            }

            if (self::selectorContainsExcludedId($selector)) {
                continue;
            }

            if (self::selectorContainsCustomerReviewsClass($selector) && !self::selectorContainsProtectedSliderKeyword($selector)) {
                continue;
            }

            if (self::selectorContainsProtectedSliderKeyword($selector)) {
                $classBlocks[] = $full;
                continue;
            }

            $filteredSelector = self::filterSelectorsToAllowedClassesAndIds($selector, $classes, $ids);
            if ($filteredSelector !== '') {
                $classBlocks[] = $filteredSelector . '{' . $body . '}';
                continue;
            }

            if (preg_match('/^\*(\s|[,>+~\[:"\']|$)/', $selTrim) || preg_match('/^:root\b/i', $selTrim)) {
                if ($isGlobal) {
                    $specialBlocks[] = $full;
                }
                continue;
            }

            $isPureTag = false;
            foreach (self::splitSelectorList($selector) as $part) {
                $classesInPart = self::extractClassesFromSelector($part);
                $idsInPart = self::extractIdsFromSelector($part);
                if ($classesInPart === [] && $idsInPart === []) {
                    if (preg_match('/^[a-zA-Z0-9_-]+/', trim($part), $m)) {
                        if (in_array(strtolower($m[0]), self::HTML_TAGS, true)) {
                            $isPureTag = true;
                            break;
                        }
                    }
                }
            }
            if ($isPureTag && $isGlobal) {
                $specialBlocks[] = $full;
            }
        }

        $finalClassBlocks = [];
        foreach ($classBlocks as $cb) {
            $sig = md5(self::minifyCss($cb));
            if (!isset($existingSignatures[$sig])) {
                $finalClassBlocks[] = $cb;
                $existingSignatures[$sig] = true;
            }
        }

        $finalSpecialBlocks = [];
        foreach ($specialBlocks as $sb) {
            $sig = md5(self::minifyCss($sb));
            if (!isset($existingSignatures[$sig])) {
                $finalSpecialBlocks[] = $sb;
                $existingSignatures[$sig] = true;
            }
        }

        return ['blocks' => $finalClassBlocks, 'specialBlocks' => $finalSpecialBlocks];
    }

    private static function filterCssBodyToAllowedClassesAndIds(
        string $cssBody,
        array $allowedClasses,
        array $allowedIds,
        bool $isGlobal = true
    ): string {
        $blocks = self::extractCssBlocks($cssBody);
        $kept = [];
        foreach ($blocks as $block) {
            $selTrim = trim((string) ($block['selector'] ?? ''));
            if ($selTrim === '') {
                continue;
            }
            if (self::selectorContainsExcludedId($block['selector'])) {
                continue;
            }

            if (self::selectorContainsCustomerReviewsClass((string) ($block['selector'] ?? ''))
                && !self::selectorContainsProtectedSliderKeyword((string) ($block['selector'] ?? ''))) {
                continue;
            }

            if (self::selectorContainsProtectedSliderKeyword((string) ($block['selector'] ?? ''))) {
                $kept[] = (string) ($block['full'] ?? '');
                continue;
            }

            if (str_starts_with($selTrim, '@')) {
                if (preg_match('/^@(?:media|supports)\b/i', $selTrim)) {
                    $inner = (string) ($block['body'] ?? '');
                    $innerFiltered = self::filterCssBodyToAllowedClassesAndIds($inner, $allowedClasses, $allowedIds, $isGlobal);
                    if ($innerFiltered !== '') {
                        $kept[] = $selTrim . '{' . $innerFiltered . '}';
                    }
                } elseif (preg_match('/^@(?:font-face|(?:-\w+-)?keyframes|charset|import|layer)\b/i', $selTrim)) {
                    $kept[] = (string) ($block['full'] ?? '');
                }
                continue;
            }

            if (! $isGlobal) {
                if (preg_match('/^\*(\s|[,>+~\[:"\']|$)/', $selTrim) || preg_match('/^:root\b/i', $selTrim)) {
                    continue;
                }
            }

            $filteredSelector = self::filterSelectorsToAllowedClassesAndIds($block['selector'], $allowedClasses, $allowedIds);
            if ($filteredSelector !== '') {
                $kept[] = $filteredSelector . '{' . $block['body'] . '}';
            }
        }

        return implode("\n", $kept);
    }

    private static function filterSelectorsToAllowedClassesAndIds(string $selectorList, array $allowedClasses, array $allowedIds): string
    {
        if (self::selectorContainsProtectedSliderKeyword($selectorList)) {
            return $selectorList;
        }
        $selectors = self::splitSelectorList($selectorList);
        $kept = [];
        foreach ($selectors as $sel) {
            if (self::selectorMatchesAllowedStrict($sel, $allowedClasses, $allowedIds)) {
                $kept[] = $sel;
            }
        }
        return $kept === [] ? '' : implode(',', $kept);
    }

    /**
     * Giữ selector đơn theo "any-match" chặt chẽ:
     * - Có >= 1 class trùng chính xác với $allowedClasses => giữ toàn bộ selector.
     * - Hoặc có >= 1 id trùng chính xác với $allowedIds / ALWAYS_ALLOWED_IDS => giữ toàn bộ selector.
     * - Không match class/id hợp lệ => loại.
     */
    private static function selectorMatchesAllowedStrict(string $singleSelector, array $allowedClasses, array $allowedIds): bool
    {
        $classesInSelector = self::extractClassesFromSelector($singleSelector);
        $idsInSelector = array_values(array_unique(array_merge(
            self::extractIdsFromSelector($singleSelector),
            self::extractIdAttributesFromSelector($singleSelector)
        )));
        if ($classesInSelector === [] && $idsInSelector === []) {
            return false;
        }
        if (self::selectorContainsCustomerReviewsClass($singleSelector) && !self::selectorContainsProtectedSliderKeyword($singleSelector)) {
            return false;
        }

        $allowedClassesSet = array_flip(array_map('strtolower', $allowedClasses));
        $allowedIdsSet = array_flip(array_map('strtolower', $allowedIds));
        $alwaysAllowedSet = array_flip(array_map('strtolower', self::ALWAYS_ALLOWED_IDS));

        // Class matching phải tuyệt đối 100% (không substring).
        foreach ($classesInSelector as $cls) {
            if (isset($allowedClassesSet[strtolower($cls)])) {
                return true;
            }
        }

        // ID matching phải tuyệt đối 100% (không substring).
        foreach ($idsInSelector as $id) {
            $li = strtolower($id);
            if (isset($allowedIdsSet[$li]) || isset($alwaysAllowedSet[$li])) {
                return true;
            }
            // MỚI: Tự động giữ lại các Widget ID động của WordPress / WooCommerce
            if (str_starts_with($li, 'woocommerce_') || str_starts_with($li, 'text-') || str_starts_with($li, 'block-') || str_starts_with($li, 'custom_html-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bỏ rule (và rule lồng @media/@supports) nếu selector chứa thẻ html/body/h1–h6/p thuần — tránh kẹp typography vào slider-*.css.
     *
     * @param list<string> $blocks Chuỗi dạng "selector{...}"
     * @return list<string>
     */
    public static function stripSliderTypographyAndDocumentNoiseFromBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $b = trim((string) $block);
            if ($b === '') {
                continue;
            }
            $cleaned = self::stripSliderTypographyNoiseFromCssFragment($b);
            if ($cleaned !== '') {
                $out[] = $cleaned;
            }
        }

        return $out;
    }

    private static function stripSliderTypographyNoiseFromCssFragment(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }
        $parts = self::extractCssBlocks($css);
        if ($parts === []) {
            return '';
        }
        $kept = [];
        foreach ($parts as $part) {
            $sel = trim((string) ($part['selector'] ?? ''));
            $body = (string) ($part['body'] ?? '');
            $full = (string) ($part['full'] ?? '');
            if ($sel === '') {
                continue;
            }
            if (preg_match('/^@(?:media|supports)\b/i', $sel)) {
                $inner = self::stripSliderTypographyNoiseFromCssFragment($body);
                if (trim($inner) !== '') {
                    $kept[] = $sel . '{' . $inner . '}';
                }
                continue;
            }
            if (str_starts_with($sel, '@')) {
                $kept[] = $full;
                continue;
            }
            if (self::selectorContainsProtectedSliderKeyword($sel)) {
                $kept[] = $full;
                continue;
            }
            if (self::selectorListContainsBareTypographyOrDocumentTags($sel)) {
                continue;
            }
            $kept[] = $full;
        }

        return trim(implode("\n", $kept));
    }

    private static function selectorContainsProtectedSliderKeyword(string $selector): bool
    {
        if (trim($selector) === '') {
            return false;
        }
        $classes = self::extractClassesFromSelector($selector);
        foreach ($classes as $cls) {
            $lower = strtolower($cls);
            if (in_array($lower, self::PROTECTED_SLIDER_KEYWORDS, true)
                || str_starts_with($lower, 'slider-')
                || str_starts_with($lower, 'flickity-')) {
                return true;
            }
        }

        return false;
    }

    private static function selectorContainsCustomerReviewsClass(string $selector): bool
    {
        foreach (self::extractClassesFromSelector($selector) as $cls) {
            if (str_starts_with(strtolower((string) $cls), 'cr-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * true nếu có thẻ h1–h6 hoặc p hoặc html/body dùng như type selector (không phải .class hay #id).
     */
    private static function selectorListContainsBareTypographyOrDocumentTags(string $selectorList): bool
    {
        foreach (self::splitSelectorList($selectorList) as $sel) {
            if (self::singleSelectorContainsBareTypographyOrDocumentTags($sel)) {
                return true;
            }
        }

        return false;
    }

    private static function singleSelectorContainsBareTypographyOrDocumentTags(string $singleSelector): bool
    {
        $s = trim($singleSelector);
        if ($s === '') {
            return false;
        }
        // Bỏ qua nội dung trong chuỗi attribute để tránh false positive
        $s = preg_replace('/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', '""', $s) ?? $s;
        $s = preg_replace("/'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'/", "''", $s) ?? $s;

        $tags = ['html', 'body', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        foreach ($tags as $tag) {
            $t = preg_quote($tag, '/');
            if (preg_match('/(?:^|[\s>+~,#])' . $t . '(?![a-zA-Z0-9_-])/i', $s)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loại specialBlocks trùng nội dung (sau minify) với specialBlocks đã có trong CSS global đã lọc.
     *
     * @param list<string> $specialBlocks
     * @return list<string>
     */
    public static function dedupeSpecialBlocksAgainstGlobalFiltered(
        string $globalRawCss,
        array $globalClasses,
        array $globalIds,
        bool $stripDarkMode,
        array $specialBlocks
    ): array {
        if ($specialBlocks === [] || trim($globalRawCss) === '') {
            return $specialBlocks;
        }
        $sigScratch = [];
        $filteredGlobal = self::filterCssByClassesAndIds($globalRawCss, $globalClasses, $globalIds, $stripDarkMode, $sigScratch, true);
        $globalSpecial = $filteredGlobal['specialBlocks'] ?? [];
        $sig = [];
        foreach ($globalSpecial as $b) {
            $sig[md5(self::minifyCss((string) $b))] = true;
        }
        $out = [];
        foreach ($specialBlocks as $b) {
            $key = md5(self::minifyCss((string) $b));
            if (!isset($sig[$key])) {
                $out[] = $b;
            }
        }

        return $out;
    }

    private static function extractClassesFromSelector(string $singleSelector): array
    {
        $singleSelector = self::stripPseudoClassArgsFromSelector($singleSelector);
        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $singleSelector, $m)) {
            return array_values(array_unique($m[1]));
        }
        return [];
    }

    private static function extractIdsFromSelector(string $singleSelector): array
    {
        $singleSelector = self::stripPseudoClassArgsFromSelector($singleSelector);
        if (preg_match_all('/#([a-zA-Z0-9_-]+)/', $singleSelector, $m)) {
            return array_values(array_unique($m[1]));
        }
        return [];
    }

    /**
     * id trong [id="x"], [id='x'], [id=x] (và biến thể |= ^= $= *= ~=).
     *
     * @return list<string>
     */
    private static function extractIdAttributesFromSelector(string $singleSelector): array
    {
        $singleSelector = self::stripPseudoClassArgsFromSelector($singleSelector);
        $out = [];
        if (preg_match_all('/\[\s*id\s*(?:\||\^|\$|\*|~)?=\s*(["\'])(.*?)\1/iu', $singleSelector, $m)) {
            foreach ($m[2] as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function stripPseudoClassArgsFromSelector(string $singleSelector): string
    {
        $len = strlen($singleSelector);
        $out = '';
        $i = 0;
        $pseudoNames = ['not', 'is', 'has', 'where'];
        while ($i < $len) {
            if ($singleSelector[$i] === ':' && $i + 1 < $len) {
                $matched = false;
                foreach ($pseudoNames as $name) {
                    $nlen = strlen($name);
                    if ($i + 1 + $nlen < $len
                        && strcasecmp(substr($singleSelector, $i + 1, $nlen), $name) === 0
                        && preg_match('/^\s*\(/', substr($singleSelector, $i + 1 + $nlen))) {
                        $start = $i;
                        $i += 1 + $nlen;
                        while ($i < $len && preg_match('/\s/', $singleSelector[$i])) {
                            $i++;
                        }
                        if ($i < $len && $singleSelector[$i] === '(') {
                            $depth = 1;
                            $i++;
                            while ($i < $len && $depth > 0) {
                                $ch = $singleSelector[$i];
                                if ($ch === '(') {
                                    $depth++;
                                } elseif ($ch === ')') {
                                    $depth--;
                                } elseif (($ch === '"' || $ch === "'") && ($i === 0 || $singleSelector[$i - 1] !== '\\')) {
                                    $quote = $ch;
                                    $i++;
                                    while ($i < $len) {
                                        if ($singleSelector[$i] === $quote && $singleSelector[$i - 1] !== '\\') {
                                            $i++;
                                            break;
                                        }
                                        $i++;
                                    }
                                    continue;
                                }
                                $i++;
                            }
                            $out .= ' ';
                            $matched = true;
                            break;
                        }
                    }
                }
                if ($matched) {
                    continue;
                }
            }
            $out .= $singleSelector[$i];
            $i++;
        }
        return $out;
    }

    private static function splitSelectorList(string $selectorList): array
    {
        $len = strlen($selectorList);
        $current = '';
        $result = [];
        $inDouble = false;
        $inSingle = false;
        $depthBracket = 0;
        $depthParen = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $selectorList[$i];
            if ($inDouble) {
                $current .= $ch;
                if ($ch === '"' && ($i === 0 || $selectorList[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                $current .= $ch;
                if ($ch === "'" && ($i === 0 || $selectorList[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                $current .= $ch;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;
                continue;
            }
            if ($ch === '[') {
                $depthBracket++;
                $current .= $ch;
                continue;
            }
            if ($ch === ']') {
                $depthBracket--;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depthParen++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depthParen--;
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && $depthBracket === 0 && $depthParen === 0) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $result[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
        return $result;
    }

    private static function selectorContainsDarkMode(string $selector): bool
    {
        return (bool) preg_match('/(\.dark\b|\.\w+-dark\b)/', $selector);
    }

    private static function filterSelectorsRemoveDark(string $selectorList): string
    {
        $selectors = self::splitSelectorList($selectorList);
        $kept = [];
        foreach ($selectors as $sel) {
            if (self::selectorContainsDarkMode($sel)) {
                continue;
            }
            $kept[] = $sel;
        }
        return $kept === [] ? '' : implode(',', $kept);
    }

    private static function stripDarkModeFromCss(string $css): string
    {
        $blocks = self::extractCssBlocks($css);
        $kept = [];
        foreach ($blocks as $block) {
            if (self::selectorContainsDarkMode($block['selector'])) {
                $filteredSelector = self::filterSelectorsRemoveDark($block['selector']);
                if ($filteredSelector === '') {
                    continue;
                }
                $kept[] = $filteredSelector . '{' . $block['body'] . '}';
            } else {
                $kept[] = $block['full'];
            }
        }
        return implode("\n", $kept);
    }

    /**
     * Bóc @font-face và @keyframes (kể cả @-webkit-keyframes) khỏi $css (xóa hẳn khỏi biến truyền vào), trả về danh sách block đã tách.
     * Quét lặp trên chuỗi: tìm @font-face / @keyframes (bỏ qua chuỗi & comment), đếm {} có tôn trọng chuỗi/comment; xóa block bằng substr; cuối cùng preg_replace dọn newline/spaces thừa.
     *
     * @return list<string>
     */
    public static function extractGlobalAtRules(string &$css): array
    {
        $extracted = [];
        $css = trim($css);
        if ($css === '') {
            return [];
        }

        $safety = 0;
        while ($safety++ < 5000) {
            $start = self::findNextFontFaceOrKeyframesAtRuleStart($css);
            if ($start === null) {
                break;
            }

            $openBrace = self::findNextUnquotedOpenBrace($css, $start);
            if ($openBrace === null) {
                break;
            }

            $closeBrace = self::findMatchingClosingBraceIndex($css, $openBrace);
            if ($closeBrace === null) {
                break;
            }

            $block = substr($css, $start, $closeBrace - $start + 1);
            if ($block === '') {
                break;
            }

            $extracted[] = $block;
            $css = substr($css, 0, $start) . substr($css, $closeBrace + 1);
            $css = trim($css);
        }

        $css = trim((string) preg_replace('/(?:\R[ \t]*){3,}/u', "\n\n", $css));
        $css = trim((string) preg_replace('/[ \t]+\R/u', "\n", $css));
        $css = trim((string) preg_replace('/\R[ \t]+/u', "\n", $css));

        return $extracted;
    }

    /** Vị trí @ của @font-face hoặc @(-vendor-)keyframes, không nằm trong chuỗi hay comment. */
    private static function findNextFontFaceOrKeyframesAtRuleStart(string $css): ?int
    {
        $len = strlen($css);
        $inDouble = false;
        $inSingle = false;
        for ($i = 0; $i < $len; $i++) {
            if ($inDouble) {
                if ($css[$i] === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                if ($css[$i] === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($css[$i] === '"') {
                $inDouble = true;
                continue;
            }
            if ($css[$i] === "'") {
                $inSingle = true;
                continue;
            }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len - 1 : $end + 1;
                continue;
            }
            if ($css[$i] !== '@') {
                continue;
            }

            $tail = substr($css, $i);
            if (preg_match('/^@font-face\b/i', $tail)) {
                return $i;
            }
            if (preg_match('/^@(?:-\w+-)?keyframes\b/i', $tail)) {
                return $i;
            }
        }

        return null;
    }

    /** Dấu { mở thân của @rule (sau prelude), bỏ qua chuỗi và comment. */
    private static function findNextUnquotedOpenBrace(string $css, int $from): ?int
    {
        $len = strlen($css);
        $inDouble = false;
        $inSingle = false;
        for ($i = $from; $i < $len; $i++) {
            if ($inDouble) {
                if ($css[$i] === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                if ($css[$i] === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($css[$i] === '"') {
                $inDouble = true;
                continue;
            }
            if ($css[$i] === "'") {
                $inSingle = true;
                continue;
            }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len - 1 : $end + 1;
                continue;
            }
            if ($css[$i] === '{') {
                return $i;
            }
        }

        return null;
    }

    /** $openIdx trỏ tới `{` mở; trả về index `}` đóng cặp (cân bằng lồng nhau). */
    private static function findMatchingClosingBraceIndex(string $css, int $openIdx): ?int
    {
        $len = strlen($css);
        $depth = 0;
        $inDouble = false;
        $inSingle = false;
        for ($i = $openIdx; $i < $len; $i++) {
            $ch = $css[$i];
            if ($inDouble) {
                if ($ch === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                if ($ch === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len - 1 : $end + 1;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    public static function removeExcludedIdRulesFromCss(string $css): string
    {
        $blocks = self::extractCssBlocks($css);
        if ($blocks === []) {
            return $css;
        }
        $kept = [];
        foreach ($blocks as $block) {
            $selector = trim((string) ($block['selector'] ?? ''));
            $body = (string) ($block['body'] ?? '');
            $full = (string) ($block['full'] ?? '');

            if ($selector === '') {
                continue;
            }

            if (preg_match('/^@(?:media|supports)\b/i', $selector)) {
                $filteredBody = self::removeExcludedIdRulesFromCss($body);
                if (trim($filteredBody) !== '') {
                    $kept[] = $selector . '{' . $filteredBody . '}';
                }
                continue;
            }

            if (self::selectorContainsExcludedId($selector)) {
                continue;
            }

            $kept[] = $full;
        }
        return implode("\n", $kept);
    }

    private static function selectorContainsExcludedId(string $selectorList): bool
    {
        foreach (self::EXCLUDED_ID_SELECTORS as $id) {
            $esc = preg_quote((string) $id, '/');
            if (preg_match('/#' . $esc . '(?:[^a-zA-Z0-9_-]|$)/i', $selectorList)) {
                return true;
            }
        }
        return false;
    }

    public static function extractCssBlocks(string $css): array
    {
        $blocks = [];
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            $i = self::skipWhitespaceAndComments($css, $i);
            if ($i >= $len) {
                break;
            }
            if (preg_match('/^@(?:charset|import)\s/i', substr($css, $i))) {
                $end = self::findSemicolonOutsideString($css, $i);
                if ($end !== null) {
                    $full = trim(substr($css, $i, $end - $i + 1));
                    if ($full !== '') {
                        $blocks[] = ['selector' => $full, 'body' => '', 'full' => $full];
                    }
                    $i = $end + 1;
                    continue;
                }
            }
            if ($css[$i] === '}') {
                $i++;
                continue;
            }
            $start = $i;
            $depth = 0;
            $inDouble = false;
            $inSingle = false;
            $open = -1;
            while ($i < $len) {
                $ch = $css[$i];
                if ($inDouble) {
                    if ($ch === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                        $inDouble = false;
                    }
                    $i++;
                    continue;
                }
                if ($inSingle) {
                    if ($ch === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                        $inSingle = false;
                    }
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = true;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = true;
                    $i++;
                    continue;
                }
                if ($ch === '{') {
                    if ($depth === 0) {
                        $open = $i;
                    }
                    $depth++;
                    $i++;
                    continue;
                }
                if ($ch === '}') {
                    $depth--;
                    if ($depth === 0 && $open >= 0) {
                        $block = substr($css, $start, $i - $start + 1);
                        $selector = trim(substr($css, $start, $open - $start));
                        $body = substr($css, $open + 1, $i - $open - 1);
                        $blocks[] = ['selector' => $selector, 'body' => $body, 'full' => $block];
                        $i++;
                        break;
                    }
                    $i++;
                    continue;
                }
                $i++;
            }
        }
        return $blocks;
    }

    private static function skipWhitespaceAndComments(string $css, int $i): int
    {
        $len = strlen($css);
        while ($i < $len) {
            if (preg_match('/^\s+/', substr($css, $i), $m)) {
                $i += strlen($m[0]);
                continue;
            }
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            break;
        }
        return $i;
    }

    private static function findSemicolonOutsideString(string $css, int $start): ?int
    {
        $len = strlen($css);
        $inDouble = false;
        $inSingle = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $css[$i];
            if ($inDouble) {
                if ($ch === '"' && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inDouble = false;
                }
                continue;
            }
            if ($inSingle) {
                if ($ch === "'" && ($i === 0 || $css[$i - 1] !== '\\')) {
                    $inSingle = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === ';') {
                return $i;
            }
        }
        return null;
    }

    public static function minifyCss(string $css): string
    {
        $css = preg_replace('/\/\*[\s\S]*?\*\//u', '', $css);
        $css = preg_replace('/[\r\n]+/u', ' ', $css);
        $css = preg_replace('/\s+/u', ' ', $css);
        $css = trim($css);
        $css = preg_replace('/@charset\s+(?:"[^"]*"|\'[^\']*\'|[^\s;]+)\s*;?\s*/iu', '', $css);
        $css = trim($css);
        $css = preg_replace('/\s*([{}:;,])\s*/u', '$1', $css);
        return trim($css);
    }
}
