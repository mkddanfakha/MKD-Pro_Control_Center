<?php

return [

    /*
    |--------------------------------------------------------------------------
    | o2switch — Hosting (TASK 357)
    |--------------------------------------------------------------------------
    |
    | Aucun appel réseau tant que `enabled` est false. Les secrets restent dans
    | .env ; ne jamais les exposer dans les résultats de provisioning.
    |
    */

    'o2switch' => [
        'hosting' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_HOSTING_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_HOSTING_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'environment' => env('PROVISIONING_O2SWITCH_ENVIRONMENT', 'production'),
        ],
        'database' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_DATABASE_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_DATABASE_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'database_name_prefix' => env('PROVISIONING_O2SWITCH_DATABASE_NAME_PREFIX', ''),
            'mysql_host_logical' => env('PROVISIONING_O2SWITCH_MYSQL_HOST_LOGICAL', 'localhost'),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'deploy' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_DEPLOY_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_DEPLOY_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'gestion_git_repository_url' => env('PROVISIONING_GESTION_GIT_REPOSITORY_URL', 'https://github.com/mkddanfakha/Gestion.git'),
            'default_git_ref' => env('PROVISIONING_GESTION_GIT_DEFAULT_REF', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'environment' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_ENVIRONMENT_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_ENVIRONMENT_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'gestion_app_base_domain' => env('PROVISIONING_GESTION_APP_BASE_DOMAIN', ''),
            'application_env' => env('PROVISIONING_GESTION_APPLICATION_ENV', 'production'),
            'application_debug' => env('PROVISIONING_GESTION_APPLICATION_DEBUG', false),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'dependencies' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_DEPENDENCIES_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_DEPENDENCIES_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'build' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_BUILD_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_BUILD_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'migrate' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_MIGRATE_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_MIGRATE_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'storage' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_STORAGE_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_STORAGE_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'cache' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_CACHE_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_CACHE_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'admin' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_ADMIN_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_ADMIN_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'modules' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_MODULES_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_MODULES_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
        'health' => [
            'provider' => 'o2switch',
            'enabled' => env('PROVISIONING_O2SWITCH_HEALTH_ENABLED', false),
            'dry_run' => env('PROVISIONING_O2SWITCH_HEALTH_DRY_RUN', false),
            'account_logical_id' => env('PROVISIONING_O2SWITCH_ACCOUNT_LOGICAL_ID', ''),
            'deployment_root_base' => env('PROVISIONING_O2SWITCH_DEPLOYMENT_ROOT_BASE', ''),
            'cpanel_host' => env('PROVISIONING_O2SWITCH_CPANEL_HOST', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gestion — contraintes dépôt (TASK 362, lecture seule GitHub)
    |--------------------------------------------------------------------------
    |
    | php ^8.2 (composer.json), package-lock.json présent → npm ci requis avant build.
    |
    */

    'gestion' => [
        'php_version_minimum' => '8.2',
        'laravel_framework_version' => '12.x',
        'node_dependencies_required' => true,
        'npm_build_script' => 'build',
        'build_manifest_relative_path' => 'public/build/manifest.json',
        'build_required_vite_keys' => [
            'VITE_APP_NAME',
        ],
        /*
         | Confirmé depuis Gestion : composer script setup + Laravel 12 production.
         | État futur : `php artisan migrate:status` (table `migrations`).
         */
        'migration_artisan_argv' => ['php', 'artisan', 'migrate', '--force'],
        'migration_status_artisan_argv' => ['php', 'artisan', 'migrate:status', '--no-ansi'],
        /*
         | Inspection Gestion config/filesystems.php + usages Storage::disk (TASK 365).
         | Disque `media` → public_path('storage') ; lien Laravel public/storage → storage/app/public.
         | Disque `s3` : backups offsite uniquement (BACKUP_DISKS) — non configuré par provisioning.
         */
        'default_filesystem_disk' => 'local',
        'application_filesystem_disks' => ['local', 'public', 'media'],
        'optional_external_filesystem_disks' => ['s3'],
        'storage_requires_storage_link' => true,
        'storage_link_artisan_argv' => ['php', 'artisan', 'storage:link'],
        'storage_link_relative' => [
            'link' => 'public/storage',
            'target' => 'storage/app/public',
        ],
        'storage_required_writable_relative_directories' => [
            'storage/app',
            'storage/app/private',
            'storage/app/public',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ],
        'storage_permission_policy' => 'platform_user_writable_no_world_chmod',
        /*
         | Inspection Gestion config/cache.php, routes/web.php (closures), Laravel 12 — TASK 366.
         | CACHE_STORE=database (tables cache/cache_locks via migration 0001_01_01_000001).
         | route:cache exclu (closures /dev/* dans web.php). optimize exclu (agrégat non contrôlé).
         */
        'cache_store_default' => 'database',
        'session_driver_default' => 'database',
        'queue_connection_default' => 'database',
        'cache_database_migration_reference' => '0001_01_01_000001_create_cache_table.php',
        'cache_warmup_artisan_steps' => [
            ['php', 'artisan', 'config:cache'],
            ['php', 'artisan', 'view:cache'],
            ['php', 'artisan', 'event:cache'],
        ],
        'cache_excluded_artisan_commands' => [
            'route:cache',
            'optimize',
            'optimize:clear',
            'cache:clear',
        ],
        'cache_execution_order' => [
            'config:cache',
            'view:cache',
            'event:cache',
        ],
        /*
         | Inspection Gestion — auth admin (TASK 367, lecture seule).
         | Pas Spatie : rôle colonne users.role ; permissions pivot user_permissions.
         | Fortify : inscription publique désactivée ; création via Admin\UserController@store.
         | Artisan : user:set-role / user:check-role (utilisateur déjà existant).
         | Aucune commande sûre de création du premier admin en unattended → plan Artisan vide.
         */
        'admin_bootstrap_mechanism' => 'admin_ui_user_store_or_manual',
        'admin_role_value' => 'admin',
        'admin_unique_identity_field' => 'email',
        'fortify_public_registration_enabled' => false,
        'permission_system' => 'custom_permissions_and_user_permissions_pivot',
        'admin_required_user_fields' => ['name', 'email', 'password', 'role', 'is_active'],
        'admin_bootstrap_logical_operations' => [
            'verify_no_active_admin_or_idempotent_replay',
            'create_admin_user_via_secured_application_channel',
            'assign_role_admin',
            'attach_permissions_from_catalog',
        ],
        'admin_bootstrap_artisan_steps' => [],
        'admin_optional_artisan_commands_documented' => [
            'user:set-role',
            'user:check-role',
        ],
        /*
         | Inspection Gestion — modules / fonctionnalités (TASK 368, lecture seule).
         | Pas de table `modules` ni de feature flags produit dans Gestion.
         | PermissionCatalog::modules() = domaines RBAC (permissions), pas des modules activables.
         | NotificationCenter = package code enregistré dans bootstrap/providers.php (toujours chargé).
         | Activation métier = attributions user_permissions + admin UI ; pas d’API toggle distant.
         */
        'modules_activation_mechanism' => 'rbac_permissions_and_deploy_time_code_packages',
        'modules_automation_protocol' => 'none_remote_toggle',
        'modules_status_artisan_argv' => ['php', 'artisan', 'rbac:sync-permission-catalog', '--status'],
        'modules_dry_run_artisan_argv' => ['php', 'artisan', 'rbac:sync-permission-catalog', '--dry-run'],
        'modules_provisioning_artisan_steps' => [],
        'modules_logical_operations_default' => [
            'verify_permission_catalog_definitions',
            'assign_permissions_via_admin_ui_manual',
        ],
        'modules_catalog' => [
            'notification_center' => [
                'label' => 'Notification Center',
                'classification' => 'A',
                'description' => 'Package app/Modules/NotificationCenter — provider bootstrap/providers.php.',
                'activatable_via_provisioning' => false,
                'accept_as_noop_request' => true,
                'logical_operations' => [],
            ],
            'rbac_permission_definitions' => [
                'label' => 'Définitions permissions RBAC',
                'classification' => 'C',
                'description' => 'Sync PermissionCatalog → table permissions (rbac:sync-permission-catalog).',
                'activatable_via_provisioning' => true,
                'accept_as_noop_request' => false,
                'depends_on_external_refs' => ['gestion_migrate_step_ready'],
                'logical_operations' => [
                    'analyze_permission_catalog_status',
                    'sync_permission_definitions_when_confirmed',
                ],
            ],
        ],
        'rbac_permission_domains_reference' => [
            'backups',
            'categories',
            'company',
            'customers',
            'dashboard',
            'delivery-notes',
            'expenses',
            'inventory',
            'products',
            'purchase-orders',
            'quotes',
            'sales',
            'suppliers',
            'user-activities',
        ],
        /*
         | Inspection Gestion — health / readiness (TASK 369, lecture seule).
         | Laravel 12 : bootstrap/app.php → health: '/up' (framework, sans middleware web/Fortify).
         | Pas de routes/api.php racine ; /api/notifications/settings/health = auth admin (NotificationCenter).
         | Contrôles distants futurs : HTTP /up, artisan migrate:status, fichiers build, refs provisioning.
         */
        'health_laravel_up_path' => '/up',
        'health_check_definitions' => [
            [
                'check_key' => 'application_http_up',
                'order' => 10,
                'type' => 'http',
                'scope' => 'health',
                'category' => 'A',
                'expected_outcome' => 'http_200',
                'logical_timeout_seconds' => 15,
                'depends_on' => [],
                'http_path' => '/up',
            ],
            [
                'check_key' => 'laravel_framework_boot',
                'order' => 20,
                'type' => 'http',
                'scope' => 'health',
                'category' => 'B',
                'expected_outcome' => 'framework_health_route_ok',
                'logical_timeout_seconds' => 15,
                'depends_on' => ['application_http_up'],
                'http_path' => '/up',
            ],
            [
                'check_key' => 'database_connectivity',
                'order' => 30,
                'type' => 'http',
                'scope' => 'health',
                'category' => 'D',
                'expected_outcome' => 'database_probe_via_laravel_up',
                'logical_timeout_seconds' => 20,
                'depends_on' => ['application_http_up'],
                'http_path' => '/up',
            ],
            [
                'check_key' => 'migration_status',
                'order' => 40,
                'type' => 'artisan',
                'scope' => 'health',
                'category' => 'E',
                'expected_outcome' => 'no_pending_migrations',
                'logical_timeout_seconds' => 60,
                'depends_on' => ['database_connectivity'],
                'artisan_argv_config_key' => 'provisioning.gestion.migration_status_artisan_argv',
            ],
            [
                'check_key' => 'storage_layout',
                'order' => 50,
                'type' => 'filesystem_relative',
                'scope' => 'health',
                'category' => 'G',
                'expected_outcome' => 'storage_link_present',
                'logical_timeout_seconds' => 30,
                'depends_on' => ['migration_status'],
                'relative_path' => 'public/storage',
            ],
            [
                'check_key' => 'cache_configuration',
                'order' => 60,
                'type' => 'external_reference',
                'scope' => 'health',
                'category' => 'F',
                'expected_outcome' => 'gestion_cache_step_ready',
                'logical_timeout_seconds' => 10,
                'depends_on' => ['migration_status'],
                'external_reference_key' => 'gestion_cache_step_ready',
            ],
            [
                'check_key' => 'vite_build_manifest',
                'order' => 70,
                'type' => 'filesystem_relative',
                'scope' => 'health',
                'category' => 'H',
                'expected_outcome' => 'manifest_json_present',
                'logical_timeout_seconds' => 15,
                'depends_on' => ['application_http_up'],
                'relative_path' => 'public/build/manifest.json',
            ],
            [
                'check_key' => 'session_driver_configuration',
                'order' => 80,
                'type' => 'external_reference',
                'scope' => 'health',
                'category' => 'M',
                'expected_outcome' => 'gestion_environment_prepared',
                'logical_timeout_seconds' => 10,
                'depends_on' => ['migration_status'],
                'external_reference_key' => 'gestion_environment_prepared',
            ],
            [
                'check_key' => 'queue_driver_configuration',
                'order' => 90,
                'type' => 'external_reference',
                'scope' => 'health',
                'category' => 'L',
                'expected_outcome' => 'gestion_environment_prepared',
                'logical_timeout_seconds' => 10,
                'depends_on' => ['migration_status'],
                'external_reference_key' => 'gestion_environment_prepared',
            ],
            [
                'check_key' => 'authentication_surface',
                'order' => 100,
                'type' => 'http',
                'scope' => 'health',
                'category' => 'I',
                'expected_outcome' => 'login_route_reachable_without_credentials',
                'logical_timeout_seconds' => 15,
                'depends_on' => ['application_http_up'],
                'http_path' => '/login',
            ],
            [
                'check_key' => 'admin_bootstrap_readiness',
                'order' => 110,
                'type' => 'external_reference',
                'scope' => 'readiness',
                'category' => 'J',
                'expected_outcome' => 'gestion_admin_bootstrap_ready',
                'logical_timeout_seconds' => 10,
                'depends_on' => ['database_connectivity', 'migration_status'],
                'external_reference_key' => 'gestion_admin_bootstrap_ready',
            ],
            [
                'check_key' => 'permission_catalog_readiness',
                'order' => 120,
                'type' => 'artisan',
                'scope' => 'readiness',
                'category' => 'K',
                'expected_outcome' => 'rbac_catalog_status_ok',
                'logical_timeout_seconds' => 45,
                'depends_on' => ['admin_bootstrap_readiness'],
                'artisan_argv_config_key' => 'provisioning.gestion.modules_status_artisan_argv',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sécurité provisioning — bases interdites pour migrate (TASK 364)
    |--------------------------------------------------------------------------
    |
    | Noms dérivés uniquement de .env Control Center (lecture config), jamais
    | exposés comme secrets. Empêche de cibler la base du Control Center.
    |
    */

    'security' => [
        'forbidden_migration_database_names' => array_values(array_unique(array_filter([
            is_string($v = env('DB_DATABASE')) ? $v : null,
            is_string($v = env('PROVISIONING_TEST_DB_DATABASE')) ? $v : null,
        ]))),
        'forbidden_storage_absolute_path_prefixes' => array_values(array_unique(array_filter([
            rtrim(str_replace('\\', '/', base_path()), '/'),
            is_string($v = env('PROVISIONING_FORBIDDEN_STORAGE_PATH_PREFIX')) ? rtrim(str_replace('\\', '/', $v), '/') : null,
        ]))),
    ],

    /*
    | Points d'injection secrets (lecture .env uniquement — jamais loggés côté adapter).
    | Aucun protocole API o2switch n'est branché tant que le gateway reste null.
    */
    /*
    |--------------------------------------------------------------------------
    | Cloudflare — DNS (TASK 358)
    |--------------------------------------------------------------------------
    |
    | API v4 documentée (Bearer token). Aucun appel tant que `enabled` est false.
    | `record_content` : cible CNAME/A requise pour une création réelle (hors dry-run).
    |
    */

    'cloudflare' => [
        'dns' => [
            'provider' => 'cloudflare',
            'enabled' => env('PROVISIONING_CLOUDFLARE_DNS_ENABLED', false),
            'dry_run' => env('PROVISIONING_CLOUDFLARE_DNS_DRY_RUN', false),
            'zone_name' => env('PROVISIONING_CLOUDFLARE_ZONE_NAME', ''),
            'zone_id' => env('PROVISIONING_CLOUDFLARE_ZONE_ID', ''),
            'record_type' => env('PROVISIONING_CLOUDFLARE_RECORD_TYPE', 'CNAME'),
            'record_content' => env('PROVISIONING_CLOUDFLARE_RECORD_CONTENT', ''),
        ],
    ],

    'secrets' => [
        'o2switch_api_token' => env('PROVISIONING_O2SWITCH_API_TOKEN'),
        'o2switch_ssh_username' => env('PROVISIONING_O2SWITCH_SSH_USERNAME'),
        'o2switch_cpanel_username' => env('PROVISIONING_O2SWITCH_CPANEL_USERNAME'),
        'cloudflare_api_token' => env('PROVISIONING_CLOUDFLARE_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Préflight infrastructure (TASK 375) — read-only, non destructif
    |--------------------------------------------------------------------------
    |
    | N'active aucun flag provisioning. Sonde Git/Fileman optionnelle.
    |
    */

    'preflight' => [
        'http_timeout_seconds' => (int) env('PROVISIONING_PREFLIGHT_HTTP_TIMEOUT', 15),
        // Probes READ-ONLY TASK 383 — chemins non sensibles (jamais .env / credentials).
        'git_probe_root' => env('PROVISIONING_PREFLIGHT_GIT_PROBE_ROOT', ''),
        'fileman_probe_dir' => env('PROVISIONING_PREFLIGHT_FILEMAN_PROBE_DIR', ''),
        'fileman_probe_file' => env('PROVISIONING_PREFLIGHT_FILEMAN_PROBE_FILE', ''),
    ],

];
