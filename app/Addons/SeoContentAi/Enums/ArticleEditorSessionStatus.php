<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ArticleEditorSessionStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case TakenOver = 'taken_over';

    public function isTerminal(): bool
    {
        return $this !== self::Active;
    }
}
