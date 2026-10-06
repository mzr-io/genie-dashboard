<?php

/*
 * Source of truth for module boundaries (AR-3, AD-2).
 *
 * 'edges': an entry `A => [B, C]` means module A may call the Contracts of B and C.
 *          There are no reverse edges; a module needing downstream data keeps a read model fed by events.
 * 'kernel': app/Platform. Every module may call it; it calls no module.
 * 'tables': the tables each module (or the kernel) owns. A module touches only its own tables.
 * 'global_tables': tables without workspace_id. Any other table must carry workspace_id.
 * 'json_decode_banned': modules that must use the lossless JSON parser (NFR exact numbers).
 */

return [
    'edges' => [
        'Dashboards' => ['Results', 'Templates', 'Blocks', 'Access'],
        'Templates' => ['Blocks', 'Access'],
        'Results' => ['Blocks', 'Ingestion', 'RawStore', 'Mapping', 'BlockTypes', 'Access'],
        'Blocks' => ['Mapping', 'Datasets', 'Ingestion', 'Access'],
        'Datasets' => ['Connector'],
        'Ingestion' => ['Connector', 'RawStore'],
        'Mapping' => ['BlockTypes'],
        'Search' => ['Access'],
        'Notifications' => ['Access'],
        'Health' => ['Ingestion'],
        'Operator' => ['Access', 'Connector'],
        'Access' => ['Identity'],
        'Identity' => [],
        'Connector' => [],
        'RawStore' => [],
        'BlockTypes' => [],
        'Settings' => [],
    ],

    'kernel' => 'Platform',

    'tables' => [
        'Platform' => [
            'workspaces', 'workspace_settings', 'outbox_events', 'outbox_consumptions',
            'audit_events', 'operator_audit', 'operations',
        ],
        'Identity' => ['users', 'sessions', 'password_reset_tokens', 'invitations'],
        'Access' => [
            'workspace_memberships', 'permissions', 'groups', 'group_members',
            'user_attribute_keys', 'user_attributes', 'access_subjects', 'access_grants',
        ],
        'Connector' => [
            'data_sources', 'endpoints', 'endpoint_revisions', 'secrets',
            'host_allowlist_entries', 'endpoint_usage',
        ],
        'Ingestion' => ['sync_targets', 'sync_subscriptions', 'sync_generations', 'sync_runs'],
        'RawStore' => ['raw_bodies', 'raw_observations'],
        'Datasets' => ['datasets', 'dataset_fields'],
        'Results' => ['block_results', 'block_viewers', 'mapping_health_incidents'],
        'Blocks' => [
            'blocks', 'block_versions', 'draft_samples', 'validation_reports',
            'block_categories', 'block_usage',
        ],
        'Templates' => ['templates', 'template_versions'],
        'Dashboards' => ['dashboards', 'dashboard_items', 'dashboard_pending_mandatory'],
        'Notifications' => ['notifications', 'notification_reads'],
        'Search' => ['search_documents'],
        'Health' => ['service_health_samples'],
    ],

    'global_tables' => [
        'users', 'sessions', 'password_reset_tokens', 'invitations',
        'service_health_samples', 'operator_audit', 'personal_access_tokens',
        // Framework job and cache tables.
        'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
    ],

    'json_decode_banned' => ['Ingestion', 'RawStore', 'Mapping', 'Results'],
];
