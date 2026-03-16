<?php

/**
 * Register ability categories exposed by this plugin.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('wp_abilities_api_categories_init', static function (): void {
    wp_register_ability_category('wp-rest', [
        'label'       => 'WordPress REST API',
        'description' => 'Abilities that wrap the WordPress REST API for full site control via MCP.',
    ]);

    wp_register_ability_category('wp-cli', [
        'label'       => 'WP-CLI',
        'description' => 'Abilities that execute WP-CLI commands or arbitrary PHP code.',
    ]);

    wp_register_ability_category('wp-admin', [
        'label'       => 'WordPress Administration',
        'description' => 'Abilities for WordPress administration tasks.',
    ]);

    wp_register_ability_category('wp-filesystem', [
        'label'       => 'WordPress Filesystem',
        'description' => 'Abilities for reading and writing files in the WordPress installation.',
    ]);
});
