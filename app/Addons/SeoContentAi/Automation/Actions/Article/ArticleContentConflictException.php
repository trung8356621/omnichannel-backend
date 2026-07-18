<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Actions\Article;

use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use RuntimeException;

final class ArticleContentConflictException extends RuntimeException
{
    public function __construct(public readonly ActionResult $result)
    {
        parent::__construct((string) ($result->error['message'] ?? 'content conflict'));
    }
}
