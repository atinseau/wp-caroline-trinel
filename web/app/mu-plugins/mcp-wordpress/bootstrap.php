<?php

/**
 * Bootstrap the WordPress MCP Adapter (Composer package) and expose
 * WordPress 6.9 core abilities (site-info, user-info, environment-info) via MCP.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Tell the adapter to skip its own autoloader — Bedrock's Composer autoloader
// already handles the WP\MCP\ namespace.
if (! defined('WP_MCP_AUTOLOAD')) {
    define('WP_MCP_AUTOLOAD', false);
}

if (! defined('WP_MCP_DIR')) {
    $mcpAdapterDir = dirname(__DIR__, 4) . '/vendor/wordpress/mcp-adapter/';
    if (! is_dir($mcpAdapterDir)) {
        return;
    }
    define('WP_MCP_DIR', $mcpAdapterDir);
}

if (! defined('WP_MCP_VERSION')) {
    define('WP_MCP_VERSION', '0.4.1');
}

if (! class_exists(\WP\MCP\Core\McpAdapter::class)) {
    return;
}

// Registers hooks on rest_api_init (priority 15) that create the default
// MCP server and register REST routes.
\WP\MCP\Core\McpAdapter::instance();

// ─── Expose WordPress 6.9 core abilities ─────────────────────────────────────
// Core abilities don't set mcp.public=true by default, so this filter injects
// the flag so they appear in MCP tools/list.

add_filter('wp_register_ability_args', static function (array $args, string $name): array {
    $exposed = [
        'core/get-site-info'        => 'tool',
        'core/get-user-info'        => 'tool',
        'core/get-environment-info' => 'tool',
    ];

    if (! isset($exposed[$name])) {
        return $args;
    }

    $args['meta']['mcp']['public'] = true;
    $args['meta']['mcp']['type']   = $exposed[$name];

    return $args;
}, 10, 2);
