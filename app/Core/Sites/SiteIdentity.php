<?php

declare(strict_types=1);

namespace App\Core\Sites;

/**
 * Site identity contract for Core — no WordPress assumptions.
 */
final class SiteIdentity
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly ?string $primaryUrl = null,
        public readonly array $attributes = [],
    ) {}
}
