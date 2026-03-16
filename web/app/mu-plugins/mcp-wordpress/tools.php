<?php

/**
 * Register all custom abilities as direct MCP tools so they appear
 * immediately in tools/list — bypassing the discover → get-info → execute
 * indirection.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_filter('mcp_adapter_default_server_config', static function (array $config): array {
    $config['tools'] = array_merge($config['tools'] ?? [], [
        // REST API
        'wp-rest/request',
        'wp-rest/list-routes',
        // WP-CLI & PHP
        'wp-cli/execute',
        'wp-php/eval',
        // Admin
        'wp-admin/manage-option',
        'wp-admin/search',
        'wp-admin/get-post-types',
        'wp-admin/get-taxonomies',
        // Filesystem
        'wp-filesystem/read-file',
        'wp-filesystem/write-file',
    ]);

    return $config;
});
