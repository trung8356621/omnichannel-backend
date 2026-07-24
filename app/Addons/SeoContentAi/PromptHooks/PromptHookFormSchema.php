<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\PromptHooks;

use App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookDefinition;
use App\Addons\SeoContentAi\PromptHooks\Canonical\PromptHookStatus;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\DefinitionNotFound;
use App\Addons\SeoContentAi\PromptHooks\Exceptions\VersionNotFound;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookEditorCatalog;
use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookRuntimeSettingsResolver;
use App\Addons\SeoContentAi\Support\ImageToolType;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Filament form: Hook select from canonical RuntimeRegistry catalog.
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
                        ->options(fn (PromptHookEditorCatalog $catalog): array => array_merge(
                            ['' => (string) __('seo-content-ai::prompt_hooks.none')],
                            $catalog->selectOptions(),
                        ))
                        ->placeholder(__('seo-content-ai::prompt_hooks.none'))
                        ->nullable()
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                            self::onHookChanged($state, $set, $get);
                        }),

                    Forms\Components\Hidden::make('hook_version'),

                    Forms\Components\Placeholder::make('hook_experimental_warning')
                        ->label('')
                        ->content(fn (Get $get): string => self::experimentalWarning((string) ($get('hook_key') ?? ''), (string) ($get('hook_version') ?? '')))
                        ->visible(fn (Get $get): bool => self::isExperimentalSelected((string) ($get('hook_key') ?? ''), (string) ($get('hook_version') ?? ''))),

                    Forms\Components\Placeholder::make('hook_template_owns_prompt')
                        ->label('')
                        ->content(fn (Get $get): string => self::templateSourceNote(
                            (string) ($get('hook_key') ?? ''),
                            (string) ($get('hook_version') ?? ''),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Placeholder::make('hook_description_display')
                        ->label(__('seo-content-ai::filament.prompt.hook_description'))
                        ->content(fn (Get $get): string => self::hookDescription(
                            (string) ($get('hook_key') ?? ''),
                            (string) ($get('hook_version') ?? ''),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Placeholder::make('hook_contract_display')
                        ->label(__('seo-content-ai::filament.prompt.hook_contract'))
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            nl2br(e(self::hookContractSummary(
                                (string) ($get('hook_key') ?? ''),
                                (string) ($get('hook_version') ?? ''),
                            ))),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Placeholder::make('hook_markdown_sections_contract')
                        ->label(__('seo-content-ai::filament.prompt.hook_sections_contract'))
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            nl2br(e(self::markdownSectionsContract(
                                (string) ($get('hook_key') ?? ''),
                                (string) ($get('hook_version') ?? ''),
                            ))),
                        ))
                        ->visible(fn (Get $get): bool => self::isMarkdownSectionsHook(
                            (string) ($get('hook_key') ?? ''),
                            (string) ($get('hook_version') ?? ''),
                        )),

                    Forms\Components\Placeholder::make('hook_input_mapping')
                        ->label(__('seo-content-ai::filament.prompt.hook_input_mapping'))
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            nl2br(e(self::inputMappingHelp(
                                (string) ($get('hook_key') ?? ''),
                                (string) ($get('hook_version') ?? ''),
                            ))),
                        ))
                        ->visible(fn (Get $get): bool => filled($get('hook_key'))),

                    Forms\Components\Group::make()
                        ->schema(fn (Get $get): array => self::settingsFields(
                            (string) ($get('hook_key') ?? ''),
                            (string) ($get('hook_version') ?? ''),
                        ))
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

        $catalog = app(PromptHookEditorCatalog::class);
        $version = trim((string) ($data['hook_version'] ?? ''));
        try {
            $definition = self::resolveDefinitionForSave($catalog, $hookKey, $version);
        } catch (DefinitionNotFound|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'hook_key' => $exception->getMessage(),
            ]);
        }

        if (($definition->model->capability ?? 'text') === 'text'
            && ImageToolType::fromMixed($data['tools'] ?? 'default')->isImagePipeline()
        ) {
            throw ValidationException::withMessages([
                'tools' => __('seo-content-ai::filament.prompt.hook_requires_text_tool'),
            ]);
        }

        $stored = is_array($data['hook_settings'] ?? null) ? $data['hook_settings'] : [];
        $allowedKeys = array_map('strval', array_keys($definition->settingsSchema));
        if ($allowedKeys !== []) {
            $stored = array_intersect_key($stored, array_flip($allowedKeys));
        } else {
            $stored = [];
        }
        $resolved = app(PromptHookRuntimeSettingsResolver::class)->resolve($definition, $stored, []);
        $data['hook_settings'] = $resolved['hook'] !== [] ? $resolved['hook'] : null;
        $data['hook_key'] = $definition->key->value;
        $data['hook_version'] = $definition->version->toString();

        return $data;
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
            $definition = app(PromptHookEditorCatalog::class)->latestPinnedOrFail($hookKey);
        } catch (\Throwable) {
            $set('hook_key', null);
            $set('hook_version', null);
            $set('hook_settings', null);

            return;
        }

        $set('hook_version', $definition->version->toString());

        $current = is_array($get('hook_settings')) ? $get('hook_settings') : [];
        $allowedKeys = array_map('strval', array_keys($definition->settingsSchema));
        if ($allowedKeys !== []) {
            $current = array_intersect_key($current, array_flip($allowedKeys));
        } else {
            $current = [];
        }
        $resolved = app(PromptHookRuntimeSettingsResolver::class)->resolve($definition, $current, []);
        $set('hook_settings', $resolved['hook']);

        if (($definition->model->capability ?? 'text') === 'text'
            && ImageToolType::fromMixed($get('tools'))->isImagePipeline()
        ) {
            $set('tools', ImageToolType::Default->value);
        }
    }

    private static function resolveDefinition(string $hookKey, string $version): ?PromptHookDefinition
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return null;
        }

        try {
            return self::resolveDefinitionForSave(
                app(PromptHookEditorCatalog::class),
                $hookKey,
                trim($version),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve hook for editor/save. Legacy int version (vd. "1" → 1.0.0) fallback latest pinned.
     *
     * @throws DefinitionNotFound
     * @throws \InvalidArgumentException
     */
    private static function resolveDefinitionForSave(
        PromptHookEditorCatalog $catalog,
        string $hookKey,
        string $version,
    ): PromptHookDefinition {
        if ($version === '') {
            return $catalog->latestPinnedOrFail($hookKey);
        }

        try {
            return $catalog->find($hookKey, $version);
        } catch (VersionNotFound) {
            return $catalog->latestPinnedOrFail($hookKey);
        }
    }

    private static function isExperimentalSelected(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);

        return $definition !== null && $definition->status === PromptHookStatus::Experimental;
    }

    private static function experimentalWarning(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        $ver = $definition?->version->toString() ?? ($version !== '' ? $version : '0.1.0');

        return (string) __('seo-content-ai::prompt_hooks.experimental_warning', ['version' => $ver]);
    }

    private static function hookDescription(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        foreach (app(PromptHookEditorCatalog::class)->optionsForTextPromptBlock() as $row) {
            if ($row['hook_key'] === $definition->key->value && $row['version'] === $definition->version->toString()) {
                return $row['description'] !== '' ? $row['description'] : '—';
            }
        }

        return $definition->description !== '' ? $definition->description : '—';
    }

    private static function hookContractSummary(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        $required = [];
        $optional = [];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            if (($schema['required'] ?? false) === true) {
                $required[] = $field;
            } else {
                $optional[] = $field;
            }
        }

        return implode("\n", [
            __('seo-content-ai::filament.prompt.hook_contract_required').': '
                .($required !== [] ? implode(', ', $required) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_optional').': '
                .($optional !== [] ? implode(', ', $optional) : '—'),
            __('seo-content-ai::filament.prompt.hook_contract_output').': '.$definition->outputSchema->type,
            'output_contract: '.($definition->outputContractKey() ?? '—'),
            __('seo-content-ai::filament.prompt.hook_contract_capability').': '.($definition->model->capability ?? 'text'),
            'version: '.$definition->version->toString(),
            'status: '.$definition->status->value,
            'template.source: '.(string) ($definition->template['source'] ?? 'inline'),
        ]);
    }

    private static function templateSourceNote(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition !== null && ($definition->template['source'] ?? '') === 'legacy_prompt_content') {
            return (string) __('seo-content-ai::prompt_hooks.hook_legacy_prompt_template_note');
        }

        return (string) __('seo-content-ai::prompt_hooks.hook_template_owns_prompt');
    }

    public static function usesLegacyPromptTemplate(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);

        return $definition !== null
            && ($definition->template['source'] ?? '') === 'legacy_prompt_content';
    }

    private static function isMarkdownSectionsHook(string $hookKey, string $version): bool
    {
        $definition = self::resolveDefinition($hookKey, $version);

        return $definition !== null && $definition->outputSchema->isMarkdownSections();
    }

    private static function markdownSectionsContract(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null || ! $definition->outputSchema->isMarkdownSections()) {
            return '—';
        }

        $lines = [];
        foreach ($definition->outputSchema->sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $task = $section['task'] ?? null;
            $label = trim((string) ($section['label'] ?? $section['key'] ?? ''));
            $start = (string) ($section['start_marker'] ?? '');
            $end = (string) ($section['end_marker'] ?? '');
            $port = (string) ($section['output_port'] ?? '');
            $taskPrefix = $task !== null && $task !== '' ? 'Task '.$task.' — ' : '';
            $lines[] = $taskPrefix.$label;
            $lines[] = $start.' ... '.$end;
            if ($port !== '') {
                $lines[] = 'output_port: '.$port;
            }
            $lines[] = '';
        }

        $totalPort = $definition->outputSchema->totalPort !== ''
            ? $definition->outputSchema->totalPort
            : 'total';
        $lines[] = 'Total (AI) → '.$totalPort.' / out_main';

        return trim(implode("\n", $lines));
    }

    private static function inputMappingHelp(string $hookKey, string $version): string
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return '—';
        }

        $lines = [(string) __('seo-content-ai::prompt_hooks.input_mapping_hint')];
        foreach ($definition->inputSchema->fields as $field => $schema) {
            $req = is_array($schema) && ($schema['required'] ?? false) === true ? ' *' : '';
            $lines[] = "{$field}{$req} ← {{".$field.'}}';
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<Forms\Components\Component>
     */
    private static function settingsFields(string $hookKey, string $version): array
    {
        $definition = self::resolveDefinition($hookKey, $version);
        if ($definition === null) {
            return [];
        }

        $fields = [];
        foreach ($definition->settingsSchema as $key => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $fields[] = self::settingField((string) $key, $schema);
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
