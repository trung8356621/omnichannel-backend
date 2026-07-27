<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Extension\Contracts;

use App\Addons\SeoContentAi\Extension\ExtensionContext;

interface ExtensionProvider
{
    public function id(): string;

    public function register(ExtensionContext $ctx): void;

    public function boot(ExtensionContext $ctx): void;
}
