<?php

declare(strict_types=1);

$files = [
    'app/Addons/SeoContentAi/tests/Unit/ArticleMetaMapTest.php',
    'app/Addons/SeoContentAi/tests/Unit/ArticlePostTypeResolverTest.php',
    'app/Addons/SeoContentAi/tests/Unit/WorkflowParserServiceTest.php',
    'app/Addons/SeoContentAi/tests/Unit/ArticleLinkSuggestionHardeningTest.php',
    'app/Addons/SeoContentAi/tests/Unit/ProductReviewAutomationPublishTest.php',
    'app/Addons/SeoContentAi/tests/Unit/ArticleLinkSuggestionContentFallbackTest.php',
];

foreach ($files as $f) {
    $c = file_get_contents($f);
    $n = str_replace('use PHPUnit\\Framework\\TestCase;', 'use Tests\\TestCase;', (string) $c);
    if ($c !== $n) {
        file_put_contents($f, $n);
        echo "OK {$f}\n";
    } else {
        echo "SKIP {$f}\n";
    }
}
