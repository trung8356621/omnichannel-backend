<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Cli;

/**
 * Static CLI command catalog — UX layer only.
 * Maps canonical CLI commands to existing Agent skills without changing registry/router.
 */
final class AgentCliCommandCatalog
{
    /**
     * @return list<array{
     *   command: string,
     *   description: string,
     *   example: string,
     *   skill_key: string|null,
     *   category: string,
     *   args: list<array{
     *     flags: list<string>,
     *     key: string,
     *     label: string,
     *     required: bool,
     *     type: string,
     *     positional?: bool
     *   }>
     * }>
     */
    public static function all(): array
    {
        return [
            // --- Content Project ---
            [
                'command' => '/project-list',
                'description' => 'List Content Projects for the current site.',
                'example' => '/project-list',
                'skill_key' => 'content_project.list',
                'category' => 'project',
                'args' => [],
            ],
            [
                'command' => '/project-view',
                'description' => 'View Content Project status and progress.',
                'example' => '/project-view --project-id=31',
                'skill_key' => 'content_project.status',
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-create',
                'description' => 'Create a new Content Project.',
                'example' => '/project-create --name="Kế hoạch tháng 8" --month="08/2026" --member=""',
                'skill_key' => 'content_project.create',
                'category' => 'project',
                'args' => [
                    ['flags' => ['--name'], 'key' => 'project_name', 'label' => 'Tên project', 'required' => true, 'type' => 'string'],
                    ['flags' => ['--month'], 'key' => 'month', 'label' => 'Tháng', 'required' => true, 'type' => 'month'],
                    ['flags' => ['--member'], 'key' => 'assignee_ref', 'label' => 'Người phụ trách', 'required' => false, 'type' => 'member'],
                ],
            ],
            [
                'command' => '/project-edit',
                'description' => 'Edit Content Project metadata.',
                'example' => '/project-edit --project-id=31 --name="Tên mới"',
                'skill_key' => 'content_project.update',
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                    ['flags' => ['--name'], 'key' => 'project_name', 'label' => 'Tên mới', 'required' => false, 'type' => 'string'],
                ],
            ],
            [
                'command' => '/project-run',
                'description' => 'Run content generation for a Content Project.',
                'example' => '/project-run --project-id=31',
                'skill_key' => 'content_project.generate',
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-review',
                'description' => 'Start review workflow for project items.',
                'example' => '/project-review --project-id=31',
                'skill_key' => 'content_project.start_review',
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-archive',
                'description' => 'Archive a Content Project.',
                'example' => '/project-archive --project-id=31',
                'skill_key' => 'content_project.archive',
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],

            // --- Team / Members ---
            [
                'command' => '/member-list',
                'description' => 'List team members on this account.',
                'example' => '/member-list',
                'skill_key' => null,
                'category' => 'member',
                'args' => [],
            ],
            [
                'command' => '/member-available',
                'description' => 'List members available for assignment.',
                'example' => '/member-available',
                'skill_key' => null,
                'category' => 'member',
                'args' => [],
            ],

            // --- Keywords ---
            [
                'command' => '/keyword-suggest',
                'description' => 'Analyze keywords and suggest clusters.',
                'example' => '/keyword-suggest --workspace=""',
                'skill_key' => 'keyword.analyze',
                'category' => 'keyword',
                'args' => [
                    ['flags' => ['--workspace'], 'key' => 'workspace_ref', 'label' => 'Keyword workspace', 'required' => false, 'type' => 'string'],
                ],
            ],
            [
                'command' => '/keyword-add-to-project',
                'description' => 'Add keywords to a Content Project (by index or manual input).',
                'example' => '/keyword-add-to-project --project-id=31 1,3,"keyword mới"',
                'skill_key' => 'content_project.add_items',
                'category' => 'keyword',
                'args' => [
                    self::projectArg(),
                    ['flags' => [], 'key' => 'keywords_tokens', 'label' => 'Keywords', 'required' => true, 'type' => 'keywords', 'positional' => true],
                ],
            ],

            // --- SEO Audit ---
            [
                'command' => '/audit-list',
                'description' => 'List articles flagged by SEO audit rules.',
                'example' => '/audit-list',
                'skill_key' => null,
                'category' => 'audit',
                'args' => [],
            ],
            [
                'command' => '/audit-keyword-suggest',
                'description' => 'Suggest focus keywords for audited articles.',
                'example' => '/audit-keyword-suggest',
                'skill_key' => null,
                'category' => 'audit',
                'args' => [],
            ],
            [
                'command' => '/audit-add-to-project',
                'description' => 'Add audited articles/keywords to a Content Project.',
                'example' => '/audit-add-to-project --project-id=31 1,3',
                'skill_key' => 'content_project.add_items',
                'category' => 'audit',
                'args' => [
                    self::projectArg(),
                    ['flags' => [], 'key' => 'keywords_tokens', 'label' => 'Items', 'required' => true, 'type' => 'keywords', 'positional' => true],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function indexByCommand(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $out[$row['command']] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function search(string $query): array
    {
        $q = strtolower(trim($query));
        $all = self::all();

        if ($q === '' || $q === '/') {
            return $all;
        }

        $needle = ltrim($q, '/');

        return array_values(array_filter(
            $all,
            static function (array $row) use ($needle): bool {
                $cmd = ltrim($row['command'], '/');
                if (str_starts_with($cmd, $needle)) {
                    return true;
                }
                if (str_contains(strtolower($row['description']), $needle)) {
                    return true;
                }
                if (str_contains(strtolower($row['category']), $needle)) {
                    return true;
                }

                return false;
            },
        ));
    }

    public static function get(string $command): ?array
    {
        $command = self::normalizeCommand($command);

        return self::indexByCommand()[$command] ?? null;
    }

    public static function normalizeCommand(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return '';
        }
        if (! str_starts_with($t, '/')) {
            $t = '/'.$t;
        }

        return strtolower($t);
    }

    /**
     * Build composer template with empty placeholders for Tab navigation.
     */
    public static function buildTemplate(array $definition): string
    {
        $parts = [$definition['command']];

        $required = [];
        $optional = [];
        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                continue;
            }
            $flag = $arg['flags'][0] ?? ('--'.$arg['key']);
            $segment = $flag.'=""';
            if ((bool) ($arg['required'] ?? false)) {
                $required[] = $segment;
            } else {
                $optional[] = $segment;
            }
        }

        foreach ($required as $segment) {
            $parts[] = $segment;
        }
        foreach ($optional as $segment) {
            $parts[] = $segment;
        }

        // Positional args hint at end for keyword commands.
        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                $parts[] = '1,3,"keyword mới"';
                break;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{flags: list<string>, key: string, label: string, required: bool, type: string}
     */
    private static function projectArg(): array
    {
        return [
            'flags' => ['--project-id', '-p'],
            'key' => 'project_ref',
            'label' => 'Content Project',
            'required' => true,
            'type' => 'project',
        ];
    }
}
