<?php

declare(strict_types=1);

namespace App\Filament\PluginReleasePanel\Pages;

use App\Exceptions\ExternalPlugin\InvalidWordPressPluginZipException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionExistsException;
use App\Exceptions\ExternalPlugin\WordPressPluginVersionNotFoundException;
use App\Models\User;
use App\Services\ExternalPlugin\ExternalPluginManifest;
use App\Services\ExternalPlugin\ExternalPluginRegistry;
use App\Services\ExternalPlugin\WordPressPluginReleaseService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class ManageExternalPluginRelease extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string $view = 'filament.pages.manage-external-plugin-release';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = '';

    public static function getSlug(): string
    {
        return '';
    }

    /** @var array<string, mixed> */
    public array $overview = [];

    public ?string $selectedPluginSlug = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(ExternalPluginRegistry $registry): void
    {
        $requested = trim((string) request()->query('name', ''));
        $manifest = $registry->resolve($requested) ?? $registry->defaultManifest();

        if ($manifest === null) {
            abort(404, 'No external plugins registered.');
        }

        $this->selectedPluginSlug = $manifest->slug;
        $this->refreshOverview();
        $this->form->fill([
            'version' => '',
            'changelog' => '',
            'overwrite' => false,
            'plugin_zip' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('seo-content-ai::filament.wp_plugin_release.upload_section'))
                    ->description(__('seo-content-ai::filament.wp_plugin_release.upload_section_description'))
                    ->schema([
                        Select::make('plugin_slug')
                            ->label(__('seo-content-ai::filament.wp_plugin.name'))
                            ->options($this->pluginSelectOptions())
                            ->default($this->selectedPluginSlug)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                if (! is_string($state) || $state === '') {
                                    return;
                                }

                                $this->selectedPluginSlug = $state;
                                $this->refreshOverview();
                            })
                            ->visible(fn (): bool => count($this->pluginSelectOptions()) > 1),
                        FileUpload::make('plugin_zip')
                            ->label(__('seo-content-ai::filament.wp_plugin_release.zip_file'))
                            ->helperText(__('seo-content-ai::filament.wp_plugin_release.zip_file_hint'))
                            ->disk('local')
                            ->directory('tmp/wp-plugin-uploads')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/zip',
                                'application/x-zip-compressed',
                                'application/octet-stream',
                            ])
                            ->maxSize(102400)
                            ->required()
                            ->maxFiles(1),
                        TextInput::make('version')
                            ->label(__('seo-content-ai::filament.wp_plugin_release.version'))
                            ->helperText(__('seo-content-ai::filament.wp_plugin_release.version_hint'))
                            ->regex('/^\d+\.\d+\.\d+(?:[-+][\w.-]+)?$/')
                            ->maxLength(32),
                        Textarea::make('changelog')
                            ->label(__('seo-content-ai::filament.wp_plugin_release.changelog'))
                            ->helperText(__('seo-content-ai::filament.wp_plugin_release.changelog_hint'))
                            ->rows(5)
                            ->maxLength(10000)
                            ->columnSpanFull(),
                        Toggle::make('overwrite')
                            ->label(__('seo-content-ai::filament.wp_plugin_release.overwrite'))
                            ->helperText(__('seo-content-ai::filament.wp_plugin_release.overwrite_hint')),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getForms(): array
    {
        return ['form'];
    }

    public function publish(ExternalPluginRegistry $registry): void
    {
        $state = $this->form->getState();
        $uploadedPath = $this->resolveUploadedZipPath($state['plugin_zip'] ?? null);

        if ($uploadedPath === null) {
            Notification::make()
                ->title(__('seo-content-ai::filament.wp_plugin_release.upload_required'))
                ->danger()
                ->send();

            return;
        }

        $releases = $this->releaseService($registry);

        try {
            $result = $releases->publishRelease(
                $uploadedPath,
                filled($state['version'] ?? null) ? (string) $state['version'] : null,
                (string) ($state['changelog'] ?? ''),
                (bool) ($state['overwrite'] ?? false),
            );
        } catch (WordPressPluginVersionExistsException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.wp_plugin_release.version_exists'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (WordPressPluginVersionNotFoundException|InvalidWordPressPluginZipException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.wp_plugin_release.publish_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            $this->cleanupUploadedZip($state['plugin_zip'] ?? null);
        }

        $this->refreshOverview($registry);
        $this->form->fill([
            'version' => '',
            'changelog' => '',
            'overwrite' => false,
            'plugin_zip' => null,
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.wp_plugin_release.publish_success'))
            ->body(__('seo-content-ai::filament.wp_plugin_release.publish_success_body', [
                'version' => $result['version'],
                'filename' => $result['filename'],
            ]))
            ->success()
            ->send();
    }

    public function refreshOverview(?ExternalPluginRegistry $registry = null): void
    {
        $registry ??= app(ExternalPluginRegistry::class);
        $this->overview = $this->releaseService($registry)->overview();
    }

    public function deleteRelease(string $version, ExternalPluginRegistry $registry): void
    {
        try {
            $this->releaseService($registry)->deleteRelease($version);
        } catch (InvalidWordPressPluginZipException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.wp_plugin_release.delete_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->refreshOverview($registry);

        Notification::make()
            ->title(__('seo-content-ai::filament.wp_plugin_release.delete_success'))
            ->body(__('seo-content-ai::filament.wp_plugin_release.delete_success_body', [
                'version' => $version,
            ]))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && in_array((string) $user->role, [User::ROLE_ADMIN, User::ROLE_OWNER], true);
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.wp_plugin_release.title');
    }

    /**
     * @return array<string, string>
     */
    private function pluginSelectOptions(): array
    {
        $options = [];
        foreach (app(ExternalPluginRegistry::class)->all() as $manifest) {
            $options[$manifest->slug] = $manifest->label.' ('.$manifest->platform.')';
        }

        return $options;
    }

    private function releaseService(ExternalPluginRegistry $registry): WordPressPluginReleaseService
    {
        $slug = trim((string) ($this->selectedPluginSlug ?? ''));
        $manifest = $registry->resolve($slug) ?? $registry->defaultManifest();

        if (! $manifest instanceof ExternalPluginManifest) {
            abort(404, 'Plugin not found.');
        }

        return WordPressPluginReleaseService::forManifest($manifest);
    }

    private function resolveUploadedZipPath(mixed $uploaded): ?string
    {
        if (is_array($uploaded)) {
            $uploaded = reset($uploaded) ?: null;
        }

        if (! is_string($uploaded) || $uploaded === '') {
            return null;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($uploaded)) {
            return null;
        }

        return $disk->path($uploaded);
    }

    private function cleanupUploadedZip(mixed $uploaded): void
    {
        if (is_array($uploaded)) {
            foreach ($uploaded as $path) {
                if (is_string($path) && $path !== '') {
                    Storage::disk('local')->delete($path);
                }
            }

            return;
        }

        if (is_string($uploaded) && $uploaded !== '') {
            Storage::disk('local')->delete($uploaded);
        }
    }
}
