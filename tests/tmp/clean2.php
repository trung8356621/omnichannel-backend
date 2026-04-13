<?php
$file = 'app/Addons/WpHeadless/Services/WpHeadlessStylesOptimizerService.php';
$content = file_get_contents($file);

$methodsToRemove = [
    'private function formatCssClass',
    'private function minifyCss',
    'private function formatCssProps',
    'private function getInlineStyleFromRow',
    'private function filterCssByClasses',
    'private function extractClassesFromSelector',
    'private function matchesSelector',
    'private function selectorContainsDarkMode',
    'private function stripPseudoClassArgsFromSelector',
    'private function removeExcludedIdRulesFromCss',
];

foreach ($methodsToRemove as $m) {
    echo "Removing $m\n";
    $pos = strpos($content, $m);
    if ($pos !== false) {
        $startPos = max(0, strrpos(substr($content, 0, $pos), '/**'));
        if ($startPos === false || ($pos - $startPos) > 300) {
            $startPos = $pos;
        }
        $braceCount = 0;
        $inBrace = false;
        $i = $pos;
        while ($i < strlen($content)) {
            if ($content[$i] === '{') {
                $braceCount++;
                $inBrace = true;
            } elseif ($content[$i] === '}') {
                $braceCount--;
            }
            $i++;
            if ($inBrace && $braceCount === 0) {
                break;
            }
        }
        $content = substr($content, 0, $startPos) . substr($content, $i);
    }
}

// Remove empty gaps and duplicated lines caused by removal
$content = preg_replace("/\n\s*\n\s*\n/", "\n\n", $content);

file_put_contents($file, $content);
echo "Cleanup 2 done.\n";
