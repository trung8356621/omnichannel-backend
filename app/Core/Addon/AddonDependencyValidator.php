<?php

declare(strict_types=1);

namespace App\Core\Addon;

use App\Core\Capability\CapabilityRegistry;

/**
 * Validates required capabilities declared by addon manifests.
 * Missing optional capabilities degrade; missing required fail validation.
 */
final class AddonDependencyValidator
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
    ) {}

    /**
     * @param  list<AddonManifest>  $manifests
     * @return list<string> Human-readable violation messages (empty = ok).
     */
    public function validate(array $manifests): array
    {
        $provided = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->provides as $cap) {
                $provided[$cap] = $manifest->slug;
            }
        }

        $violations = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest->requires as $cap) {
                $runtimeHas = $this->capabilities->has($cap);
                $peerProvides = isset($provided[$cap]);
                if (! $runtimeHas && ! $peerProvides) {
                    $violations[] = "Addon [{$manifest->slug}] requires capability [{$cap}] but none provide it.";
                }
            }
        }

        return $violations;
    }
}
