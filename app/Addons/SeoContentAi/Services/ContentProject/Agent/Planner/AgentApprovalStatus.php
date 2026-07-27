<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Agent\Planner;

final class AgentApprovalStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';
}
