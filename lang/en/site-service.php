<?php

declare(strict_types=1);

return [
    'seo_db_section_title' => 'SEO Content AI database configuration',
    'seo_db_section_description' => 'Site service only selects the DB mode. Create specific connections (host, user, password) under SEO Database Connections.',
    'seo_db_config_mode' => 'DB configuration mode',
    'seo_db_mode_auto' => 'Automatic (Docker production)',
    'seo_db_mode_manual' => 'Manual (individual hosting / clone)',
    'seo_db_auto_note' => 'Automatic mode note',
    'seo_db_auto_per_site' => 'Uses MySQL credentials from the core .env, database: :database. The system syncs the SEO Database Connection record on save.',
    'seo_db_auto_shared' => 'Uses MySQL credentials from the core .env, shared database: :database. The system syncs the SEO Database Connection record on save.',
    'seo_db_manual_note' => 'Manual mode',
    'seo_db_manual_hint' => 'In manual mode, host, port, database, user and password are configured at :link. You can create the connection after this site service is saved.',
    'seo_db_invalid_owner_context' => 'Select a valid site or owner for SEO database configuration.',
    'seo_db_invalid_manual_owner' => 'Invalid owner for manual DB mode.',
    'seo_db_manual_create_connection_later' => 'Site service saved in manual mode. Create the SEO Database Connection for this owner when ready.',
    'seo_db_config_error_title' => 'SEO database configuration error',
    'seo_db_activated_title' => 'SEO Content AI activated',
    'seo_db_ready_title' => 'SEO database is ready',
    'seo_db_connected_no_migrations' => 'SEO database connected. No pending migrations.',
    'seo_db_connected_reconciled' => 'SEO database connected. Synced :count existing CREATE migrations on the database.',
    'seo_db_migrations_applied' => 'Applied :count pending migration(s).',
    'seo_db_migrations_reconciled_suffix' => ' Also synced :count CREATE migration(s) for tables that already exist.',

    'bound_select_owner' => 'Select an owner when bound to user.',
    'bound_owner_only' => 'Only owners can be bound directly by user.',
    'bound_select_site' => 'Select a site when bound to site.',

    'system_nav_group' => 'System',
    'manage_services_nav' => 'Manage services',
    'manage_services_title' => 'Addon & service system',
    'service_not_found' => 'Service not found',
    'cannot_activate_addon' => 'Cannot activate addon',
    'database_not_created' => 'Database ":name" does not exist yet. Create it (and run addon migrations) before activating.',
    'status_updated' => 'Status updated successfully',
    'create_database_error' => 'Could not save to database. Run pending core migrations (site_services bound_type columns) and try again.',
];
