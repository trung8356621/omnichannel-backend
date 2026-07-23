<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model cho bảng automation_* / business_events trên connection cấu hình.
 */
abstract class AutomationModel extends Model
{
    public function getConnectionName(): ?string
    {
        $configured = config('automation.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return parent::getConnectionName();
    }
}
