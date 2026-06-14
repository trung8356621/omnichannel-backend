<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Exceptions\InvalidWordPressPluginZipException;
use App\Addons\SeoContentAi\Exceptions\WordPressPluginVersionExistsException;
use App\Addons\SeoContentAi\Exceptions\WordPressPluginVersionNotFoundException;
use App\Addons\SeoContentAi\Services\WordPressPluginReleaseService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class ManagePluginRelease extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/wp-plugin-release';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'seo-content-ai::filament.pages.manage-plugin-release';

    /** @var array<string, mixed> */
    public array $overview = [];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(WordPressPluginReleaseService $releases): void
    {
        $this->refreshOverview($releases);
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

    public function publish(WordPressPluginReleaseService $releases): void
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

        $this->refreshOverview($releases);
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

    public function refreshOverview(WordPressPluginReleaseService $releases): void
    {
        $this->overview = $releases->overview();
    }

    public function deleteRelease(string $version, WordPressPluginReleaseService $releases): void
    {
        try {
            $releases->deleteRelease($version);
        } catch (InvalidWordPressPluginZipException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.wp_plugin_release.delete_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->refreshOverview($releases);

        Notification::make()
            ->title(__('seo-content-ai::filament.wp_plugin_release.delete_success'))
            ->body(__('seo-content-ai::filament.wp_plugin_release.delete_success_body', [
                'version' => $version,
            ]))
            ->success()
            ->send();
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

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.wp_plugin_release.navigation');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.wp_plugin_release.title');
    }
}
