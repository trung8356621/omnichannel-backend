<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleEditor\Document;

use RuntimeException;

final class ArticleEditorDocumentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
