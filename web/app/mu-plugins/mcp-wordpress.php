<?php

/**
 * Plugin Name:  MCP WordPress
 * Description:  Full WordPress control via MCP — REST API, WP-CLI, PHP eval, filesystem,
 *               options management, and content search. 10 abilities, all require admin.
 * Version:      1.0.0
 * Author:       Arthur
 * License:      GPL-2.0-or-later
 *
 * @package WordPress
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$mcp_dir = __DIR__ . '/mcp-wordpress';

require_once $mcp_dir . '/bootstrap.php';
require_once $mcp_dir . '/categories.php';
require_once $mcp_dir . '/tools.php';
require_once $mcp_dir . '/abilities-rest.php';
require_once $mcp_dir . '/abilities-cli.php';
require_once $mcp_dir . '/abilities-admin.php';
require_once $mcp_dir . '/abilities-filesystem.php';
