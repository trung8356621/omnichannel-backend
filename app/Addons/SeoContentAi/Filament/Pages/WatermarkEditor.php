<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoWatermarkSetting;
use App\Addons\SeoContentAi\Services\SeoMediaLibraryService;
use App\Addons\SeoContentAi\Services\SeoWatermarkOverlayStorage;
use App\Models\Site;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;

class WatermarkEditor extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationLabel = 'Thiết kế đóng dấu';

    protected static ?string $title = 'Bộ thiết kế Watermark';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationParentItem = 'Thư viện hình ảnh';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'seo-content-ai::filament.pages.watermark-editor';

    protected static bool $shouldRegisterNavigation = true;

    #[Url]
    public ?int $siteId = null;

    #[Url]
    public ?string $imageUrl = null;

    #[Url]
    public ?int $imageId = null;

    public function mount(): void
    {
        if ($this->siteId === null) {
            $firstSite = $this->resolveSitesQuery()->first();
            $this->siteId = $firstSite instanceof Site ? (int) $firstSite->id : null;
        }
    }

    public function updatedSiteId(): void
    {
        $this->imageUrl = null;
        $this->imageId = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInitialDesignConfig(): array
    {
        if ($this->siteId === null) {
            return (new SeoWatermarkSetting())->defaultDesignConfig();
        }

        $setting = SeoWatermarkSetting::query()->where('site_id', $this->siteId)->first();
        if ($setting === null) {
            return (new SeoWatermarkSetting())->defaultDesignConfig();
        }

        $design = is_array($setting->design_config) && $setting->design_config !== []
            ? $setting->design_config
            : $setting->defaultDesignConfig();

        if (filled($setting->logoUrl())) {
            $design['logoUrl'] = $setting->logoUrl();
        }

        $design['overlay_previews'] = app(SeoWatermarkOverlayStorage::class)->variantsForEditor($design);

        return $design;
    }

    /**
     * @return list<array{id: int|string, url: string, slug: string, source: string}>
     */
    public function getMediaSamples(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        $site = Site::query()->find($this->siteId);
        if (! $site instanceof Site) {
            return [];
        }

        $samples = [];

        $result = app(SeoMediaLibraryService::class)->fetch($site, null, 1, null, 24);
        foreach ($result['images'] ?? [] as $row) {
            if (! is_array($row) || empty($row['url'])) {
                continue;
            }
            $samples[] = [
                'id' => (int) ($row['seo_media_id'] ?? $row['id'] ?? 0),
                'url' => (string) $row['url'],
                'slug' => (string) ($row['slug'] ?? ''),
                'source' => 'local',
            ];
        }

        if ($this->imageUrl && ! collect($samples)->contains('url', $this->imageUrl)) {
            array_unshift($samples, [
                'id' => $this->imageId ?? 0,
                'url' => $this->imageUrl,
                'slug' => 'current',
                'source' => 'picker',
            ]);
        }

        return $samples;
    }

    public function getEditorUrl(): string
    {
        return static::getUrl([
            'siteId' => $this->siteId,
            'imageUrl' => $this->imageUrl,
            'imageId' => $this->imageId,
        ]);
    }

    /**
     * @return Collection<int, Site>
     */
    public function getSitesProperty(): Collection
    {
        return $this->resolveSitesQuery()->get();
    }

    private function resolveSitesQuery()
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }
}
