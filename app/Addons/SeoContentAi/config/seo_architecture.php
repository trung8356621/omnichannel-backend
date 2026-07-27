<?php

declare(strict_types=1);

return [
    'sdk_version' => 1,
    'core_capabilities_protected_prefix' => 'content_project.',
    'extension_id_pattern' => '/^[a-z0-9][a-z0-9._-]*$/',
    'event_versions' => [
        'content_project.created' => 'v1',
        'content_project.generated' => 'v1',
        'article.published' => 'v1',
        'content_project.archived' => 'v1',
    ],
    'forbidden_dependency_rules' => [
        ['from' => 'Application/Handlers', 'forbid_import' => 'Extension\\Builtin\\Wordpress\\WordPressPublisher'],
        ['from' => 'Application/Handlers', 'forbid_import' => 'WordPressContentPublisher'],
        ['from' => 'Agent', 'forbid_import' => 'Extension\\Builtin'],
        ['from' => 'Agent', 'forbid_import' => 'WordPressArticleSyncService'],
    ],
    'public_reference_prefixes' => ['cp_', 'cpi_'],
];
