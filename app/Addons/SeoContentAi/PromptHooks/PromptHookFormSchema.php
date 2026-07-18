<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks;

use App\Addons\SeoContentAi\PromptHooks\Exceptions\PromptHookException;
use App\Addons\SeoContentAi\Support\ImageToolType;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Filament form blocks cho chọn Hook + settings động từ manifest.
 */
final class PromptHookFormSchema
{
    /**
     * @return list<Forms\Components\Component>
     */
    public static function section(): array
    {
        return [
            Forms\Components\Section::make(__('seo-content-ai::filament.prompt.hook_section'))
                ->description(__('seo-content-ai::filament.prompt.hook_section_description'))
                ->schema([
                    Forms\Components\Select::make('hook_key')
                        ->label(__('seo-content-ai::filament.prompt.hook'))
                        ->options(fn (PromptHookRegistry $registry): array => self::hookOptions($registry))
                        ->placeholder(__('seo-content-ai::prompt_hooks.none'))
                        ->nullable()
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                            self::onHookChanged($state, $set, $get);
                        }),

                    Forms\Components\Hidden::make('hook_version'),

                    Forms\Components\Placeholder::make('hook_description_display')
                        ->label(__('seo-content-ai::filament.prompt.hook_description'))
                        ->content(fn (Get $get): string => self::hookDescription((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Placeholder::make('hook_contract_display')
                        ->label(__('seo-content-ai::filament.prompt.hook_contract'))
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            nl2br(e(self::hookContractSummary((string) ($get('hook_key') ?? '')))),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Group::make()
                        ->schema(fn (Get $get): array => self::settingsFields((string) ($get('hook_key') ?? '')))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeForSave(array $data): array
    {
        $hookKey = trim((string) ($data['hook_key'] ?? ''));
        if ($hookKey === '') {
            $data['hook_key'] = null;
            $data['hook_version'] = null;
            $data['hook_settings'] = null;

            return $data;
        }

        $registry = app(PromptHookRegistry::class);
        try {
            $definition = $registry->get($hookKey);
        } catch (PromptHookException $exception) {
            throw ValidationException::withMessages([
                'hook_key' => $exception->getMessage(),
            ]);
        }

        if ($definition->capability() === 'text'
            && ImageToolType::fromMixed($data['tools'] ?? 'default')->isImagePipeline()
        ) {
            throw ValidationException::withMessages([
                'tools' => __('seo-content-ai::filament.prompt.hook_requires_text_tool'),
            ]);
        }

        $stored = is_array($data['hook_settings'] ?? null) ? $data['hook_settings'] : null;

        try {
            $data['hook_settings'] = app(PromptHookSettingsResolver::class)->resolve($definition, $stored);
        } catch (PromptHookException $exception) {
            throw ValidationException::withMessages([
                'hook_settings' => $exception->getMessage(),
            ]);
        }

        $data['hook_key'] = $definition->key;
        $data['hook_version'] = $definition->version;

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function hookOptions(PromptHookRegistry $registry): array
    {
        $options = [];
        foreach ($registry->all() as $definition) {
            $options[$definition->key] = $definition->label();
        }

        return $options;
    }

    private static function onHookChanged(?string $state, Set $set, Get $get): void
    {
        $hookKey = trim((string) $state);
        if ($hookKey === '') {
            $set('hook_key', null);
            $set('hook_version', null);
            $set('hook_settings', null);

            return;
        }

        try {
            $definition = app(PromptHookRegistry::class)->get($hookKey);
        } catch (PromptHookException) {
            $set('hook_key', null);
            $set('hook_version', null);
            $set('hook_settings', null);

            return;
        }

        $set('hook_version', $definition->version);

        $current = is_array($get('hook_settings')) ? $get('hook_settings') : null;
        $normalized = app(PromptHookSettingsResolver::class)->normalizeForDefinition($definition, $current);
        $set('hook_settings', $normalized);

        if ($definition->capability() === 'text'
            && ImageToolType::fromMixed($get('tools'))->isImagePipeline()
        ) {
            $set('tools', ImageToolType::Default->value);
        }
    }

    private static function hookDescription(string $hookKey): string
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return '—';
        }

        try {
            return app(PromptHookRegistry::class)->get($hookKey)->description();
        } catch (PromptHookException) {
            return '—';
        }
    }

    private static function hookContractSummary(string $hookKey): string
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return '—';
        }

        try {
            $definition = app(PromptHookRegistry::class)->get($hookKey);
        } catch (PromptHookException) {
            return '—';
        }

        $required = [];
        $optional = [];
        foreach ($definition->inputFields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['required'] ?? false) === true) {
                $required[] = $field;
            } else {
                $optional[] = $field;
            }
        }

        $lines = [
            __('seo-content-ai::filament.prompt.hook_contract_required').': '
                .($required !== [] ? implode(', ', $required) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_optional').': '
                .($optional !== [] ? implode(', ', $optional) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_output').': '.$definition->outputFormat(),
            __('seo-content-ai::filament.prompt.hook_contract_capability').': '.$definition->capability(),
        ];

        return implode("\n", $lines);
    }

    /**
     * @return list<Forms\Components\Component>
     */
    private static function settingsFields(string $hookKey): array
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return [];
        }

        try {
            $definition = app(PromptHookRegistry::class)->get($hookKey);
        } catch (PromptHookException) {
            return [];
        }

        $fields = [];
        foreach ($definition->settings as $key => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $fields[] = self::settingField($key, $schema);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function settingField(string $key, array $schema): Forms\Components\Component
    {
        $labelKey = (string) ($schema['label_key'] ?? '');
        $label = $labelKey !== ''
            ? (string) __('seo-content-ai::'.$labelKey)
            : $key;

        $type = (string) ($schema['type'] ?? 'string');

        if (in_array($type, ['boolean', 'bool'], true)) {
            return Forms\Components\Toggle::make('hook_settings.'.$key)
                ->label($label)
                ->default((bool) ($schema['default'] ?? false));
        }

        if (in_array($type, ['integer', 'int', 'number', 'float'], true)) {
            $input = Forms\Components\TextInput::make('hook_settings.'.$key)
                ->label($label)
                ->numeric()
                ->required()
                ->default($schema['default'] ?? null);

            if (isset($schema['min'])) {
                $input->minValue((float) $schema['min']);
            }
            if (isset($schema['max'])) {
                $input->maxValue((float) $schema['max']);
            }

            return $input;
        }

        return Forms\Components\TextInput::make('hook_settings.'.$key)
            ->label($label)
            ->default($schema['default'] ?? null);
    }
}
