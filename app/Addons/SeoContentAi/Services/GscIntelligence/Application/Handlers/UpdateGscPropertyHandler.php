<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\GscIntelligence\Application\Handlers;

use App\Addons\SeoContentAi\Enums\Gsc\GscPropertyType;
use App\Addons\SeoContentAi\Enums\Gsc\GscSearchType;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ActorContext;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use App\Addons\SeoContentAi\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\Commands\UpdateGscPropertyCommand;
use App\Addons\SeoContentAi\Services\GscIntelligence\Application\GscIntelligenceActionCodes;
use App\Addons\SeoContentAi\Services\KeywordIntelligence\Application\Support\KeywordIntelligenceTenantGuard;
use InvalidArgumentException;

final class UpdateGscPropertyHandler extends AbstractGscIntelligenceHandler
{
    public function __construct(
        KeywordIntelligenceTenantGuard $tenantGuard,
        ContentProjectPreviewToken $previewToken,
    ) {
        parent::__construct($tenantGuard, $previewToken);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof UpdateGscPropertyCommand) {
            throw new InvalidArgumentException('Expected UpdateGscPropertyCommand.');
        }

        return $this->wrap(function () use ($command, $actor): ContentProjectActionResult {
            $property = $this->resolveProperty($command->propertyRef);
            $this->assertCanAccessProperty($property, $actor);
            $this->assertPropertyActive($property);

            $attrs = $command->attributes;
            if (isset($attrs['display_name'])) {
                $property->display_name = (string) $attrs['display_name'];
            }
            if (isset($attrs['property_type'])) {
                $property->property_type = GscPropertyType::tryFrom((string) $attrs['property_type']) ?? $property->property_type;
            }
            if (isset($attrs['sync_enabled'])) {
                $property->sync_enabled = (bool) $attrs['sync_enabled'];
            }
            if (isset($attrs['default_country'])) {
                $property->default_country = (string) $attrs['default_country'];
            }
            if (isset($attrs['default_search_type'])) {
                $property->default_search_type = GscSearchType::tryFrom((string) $attrs['default_search_type']) ?? $property->default_search_type;
            }
            if (isset($attrs['timezone'])) {
                $property->timezone = (string) $attrs['timezone'];
            }
            if (isset($attrs['settings']) && is_array($attrs['settings'])) {
                $property->settings = array_merge((array) ($property->settings ?? []), $attrs['settings']);
            }
            if (isset($attrs['metadata']) && is_array($attrs['metadata'])) {
                $property->metadata = array_merge((array) ($property->metadata ?? []), $attrs['metadata']);
            }

            $property->save();

            return ContentProjectActionResult::ok(
                GscIntelligenceActionCodes::PROPERTY_UPDATED,
                'GSC property updated.',
                metadata: ['property_ref' => $property->public_ref],
            );
        });
    }
}
