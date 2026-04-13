<?php
$file = 'app/Addons/WpHeadless/Services/WpHeadlessStylesOptimizerService.php';
$content = file_get_contents($file);

$methodsToRemove = [
    'private const HTML_TAGS = \[',
    'private const EXCLUDED_ID_SELECTORS = \[',
    'private function selectorContainsDarkMode',
    'private function stripPseudoClassArgsFromSelector',
    'private function extractClassesFromSelector',
    'private function selectorAllClassesInList',
    'private function filterSelectorsToAllowedClassesOnly',
    'private function selectorHasAtLeastOneClassInList',
    'private function selectorContainsExcludedId',
    'private function filterCssBodyToAllowedClassesOnly',
    'private function splitSelectorList',
    'private function filterSelectorsRemoveDark',
    'private function filterCssByClasses',
    'private function stripDarkModeFromCss',
    'public function removeExcludedIdRulesFromCss',
    'private function removeExcludedIdRulesFromCss',
    'private function extractCssBlocks',
    'public function extractCssBlocks',
    'private function skipWhitespaceAndComments',
    'private function findSemicolonOutsideString',
    'private function minifyCss'
];

foreach ($methodsToRemove as $m) {
    if (strpos($m, 'const ') !== false) {
        $content = preg_replace('/^[ \t]*(?:\/\*\*.*?\*\/[ \t]*[\r\n]+)?[ \t]*' . $m . '.*?\];/ms', '', $content);
    } else {
        $pattern = '/^[ \t]*(?:\/\*\*.*?\*\/[ \t]*[\r\n]+)?[ \t]*' . $m . '\b.*?\{((?:[^{}]++|(?1))*)\}/ms';
        $content = preg_replace($pattern, '', $content);
    }
}

// Fixed multiple blank lines
$content = preg_replace('/(\r?\n){3,}/', "\n\n", $content);

file_put_contents($file, $content);
echo "Cleanup done.\n";
