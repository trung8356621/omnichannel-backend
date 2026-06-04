<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support;

/**
 * Chuyển Markdown (AI / debug import) sang HTML cho editor bài viết.
 */
final class SimpleMarkdownHtmlConverter
{
    /**
     * @return array{html: string, meta_description: string|null}
     */
    public function toHtmlWithMetadata(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return ['html' => '', 'meta_description' => null];
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $inList = false;
        $metaDescription = null;

        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            $trimmed = trim($line);

            if ($trimmed === '') {
                $this->closeList($html, $inList);

                continue;
            }

            if ($this->isMetaDescriptionLine($trimmed)) {
                $this->closeList($html, $inList);
                $metaDescription = $this->parseMetaDescriptionLine($trimmed);

                continue;
            }

            if ($this->isHorizontalRule($trimmed)) {
                $this->closeList($html, $inList);
                $html[] = '<hr>';

                continue;
            }

            $tableHtml = $this->tryParseTable($lines, $index);
            if ($tableHtml !== null) {
                $this->closeList($html, $inList);
                $html[] = $tableHtml;

                continue;
            }

            $heading = $this->parseHeading($trimmed);
            if ($heading !== null) {
                $this->closeList($html, $inList);
                if ($heading['level'] === 1) {
                    if ($this->isSpuriousHashStarHeadingLine($trimmed)) {
                        $html[] = '<p>' . $this->formatInline(preg_replace('/^#\s+/u', '', $trimmed) ?? $trimmed) . '</p>';
                    }

                    continue;
                }
                $level = $heading['level'];
                $html[] = sprintf(
                    '<h%d>%s</h%d>',
                    $level,
                    $this->formatInline($heading['text']),
                    $level,
                );

                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                if (! $inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . $this->formatInline($matches[1]) . '</li>';

                continue;
            }

            $this->closeList($html, $inList);
            $html[] = '<p>' . $this->formatInline($trimmed) . '</p>';
        }

        $this->closeList($html, $inList);

        return [
            'html' => implode("\n", $html),
            'meta_description' => $metaDescription,
        ];
    }

    public function toHtml(string $markdown): string
    {
        return $this->toHtmlWithMetadata($markdown)['html'];
    }

    /**
     * Tách H1 / Meta Description khỏi markdown trước khi convert (H1 → tiêu đề, không vào body).
     *
     * @return array{markdown: string, h1_title: string|null, meta_description: string|null}
     */
    public function prepareImport(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return ['markdown' => '', 'h1_title' => null, 'meta_description' => null];
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $h1Title = null;
        $metaDescription = null;
        $result = [];
        $h1Stripped = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '' && $metaDescription === null && $this->isMetaDescriptionLine($trimmed)) {
                $metaDescription = $this->parseMetaDescriptionLine($trimmed);

                continue;
            }

            if (! $h1Stripped && $trimmed !== '') {
                $h1 = $this->parseH1Line($trimmed);
                if ($h1 !== null && $h1Title === null) {
                    $h1Title = $h1;
                    $h1Stripped = true;

                    continue;
                }
            }

            $result[] = $line;
        }

        return [
            'markdown' => trim(implode("\n", $result)),
            'h1_title' => $h1Title,
            'meta_description' => $metaDescription,
        ];
    }

    private function parseH1Line(string $line): ?string
    {
        if (preg_match('/^H1:\s*(.+)$/iu', $line, $matches) === 1) {
            return $this->cleanPlainHeadingText($matches[1]);
        }

        if (preg_match('/^#{1,6}\s+H1:\s*(.+)$/iu', $line, $matches) === 1) {
            return $this->cleanPlainHeadingText($matches[1]);
        }

        if (preg_match('/^#\s+(?!#)(.+)$/u', $line, $matches) === 1) {
            if ($this->isSpuriousHashStarHeadingLine($line)) {
                return null;
            }

            $text = $matches[1];
            if (preg_match('/^\*\s+/u', $text) === 1) {
                $text = preg_replace('/^\*\s+/u', '', $text) ?? $text;
            }

            $title = $this->cleanPlainHeadingText($text);

            return $title !== '' ? $title : null;
        }

        if (preg_match('/^\*{0,2}\s*H1\s*:\s*(.+?)\*{0,2}\s*$/iu', $line, $matches) === 1) {
            return $this->cleanPlainHeadingText($matches[1]);
        }

        return null;
    }

    private function cleanPlainHeadingText(string $text): string
    {
        $text = trim(str_replace(['**', '*'], '', $text));
        $text = $this->stripHeadingLabelPrefixes($text);

        return trim($text);
    }

    private function isMetaDescriptionLine(string $line): bool
    {
        return preg_match('/^\*{0,2}\s*Meta\s+Description\s*:\*{0,2}/iu', $line) === 1;
    }

    private function parseMetaDescriptionLine(string $line): string
    {
        if (preg_match('/^\*{0,2}\s*Meta\s+Description\s*:\*{0,2}\s*(.*)$/iu', $line, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function isHorizontalRule(string $line): bool
    {
        return preg_match('/^-{3,}\s*$/u', $line) === 1;
    }

    /**
     * @return array{level: int, text: string}|null
     */
    private function parseHeading(string $line): ?array
    {
        if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $matches) === 1) {
            return [
                'level' => strlen($matches[1]),
                'text' => $this->stripHeadingLabelPrefixes($matches[2]),
            ];
        }

        if (preg_match('/^Section\s+(\d+):\s*H([1-6]):\s*(.+)$/iu', $line, $matches) === 1) {
            return [
                'level' => (int) $matches[2],
                'text' => 'Section ' . $matches[1] . ': ' . $this->stripHeadingLabelPrefixes($matches[3]),
            ];
        }

        if (preg_match('/^H([1-6]):\s*(.+)$/iu', $line, $matches) === 1) {
            return [
                'level' => (int) $matches[1],
                'text' => $this->stripHeadingLabelPrefixes($matches[2]),
            ];
        }

        return null;
    }

    private function stripHeadingLabelPrefixes(string $text): string
    {
        $text = preg_replace('/\bH([1-6]):\s*/iu', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Dòng «# *» rỗng / chỉ dấu * — không phải H1 (tránh nuốt dòng và bỏ qua tiêu đề thật phía sau).
     */
    private function isSpuriousHashStarHeadingLine(string $line): bool
    {
        $line = trim($line);
        if (! preg_match('/^#\s+\*/u', $line)) {
            return false;
        }

        $after = preg_replace('/^#\s+\*/u', '', $line) ?? '';

        return trim(str_replace('*', '', $after)) === '';
    }

    /**
     * @param  list<string>  $lines
     */
    private function tryParseTable(array $lines, int &$index): ?string
    {
        $row = $this->parseTableRow($lines[$index]);
        if ($row === null) {
            return null;
        }

        $block = [$row];
        $cursor = $index + 1;

        while ($cursor < count($lines)) {
            $nextRow = $this->parseTableRow($lines[$cursor]);
            if ($nextRow === null) {
                break;
            }

            $block[] = $nextRow;
            $cursor++;
        }

        if (count($block) < 2) {
            return null;
        }

        $index = $cursor - 1;

        return $this->renderTable($block);
    }

    /**
     * @return list<string>|null
     */
    private function parseTableRow(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '' || ! str_contains($trimmed, '|')) {
            return null;
        }

        $trimmed = trim($trimmed, '|');
        $cells = array_map(static fn (string $cell): string => trim($cell), explode('|', $trimmed));

        if ($cells === [] || $this->cellsAreEmpty($cells)) {
            return null;
        }

        return $cells;
    }

    /**
     * @param  list<string>  $cells
     */
    private function cellsAreEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function renderTable(array $rows): string
    {
        $header = $rows[0];
        $bodyRows = array_slice($rows, 1);

        if ($bodyRows !== [] && $this->isSeparatorRow($bodyRows[0])) {
            $bodyRows = array_slice($bodyRows, 1);
        }

        $html = ['<table>'];
        $html[] = '<thead><tr>';
        foreach ($header as $cell) {
            $html[] = '<th>' . $this->formatInline($cell) . '</th>';
        }
        $html[] = '</tr></thead>';

        if ($bodyRows !== []) {
            $html[] = '<tbody>';
            foreach ($bodyRows as $row) {
                $html[] = '<tr>';
                foreach ($row as $cell) {
                    $html[] = '<td>' . $this->formatInline($cell) . '</td>';
                }
                $html[] = '</tr>';
            }
            $html[] = '</tbody>';
        }

        $html[] = '</table>';

        return implode('', $html);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isSeparatorRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{1,}:?$/u', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function formatInline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\*\*(.+?)\*\*/us', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*]+?)\*(?!\*)/us', '<i>$1</i>', $text) ?? $text;

        return $text;
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
