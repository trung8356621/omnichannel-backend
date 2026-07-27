<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

interface WorkflowContributor
{
    /**
     * @return list<array{id: string, label: string}>
     */
    public function workflows(): array;
}
