<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\Pages;

use App\Addons\SeoContentAi\Filament\Concerns\InteractsWithSeoFilamentFormSaveActions;
use Filament\Resources\Pages\CreateRecord;

abstract class SeoCreateRecord extends CreateRecord
{
    use InteractsWithSeoFilamentFormSaveActions;
}
