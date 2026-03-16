<?php

/**
 * WordPress administration abilities
 *
 *   wp-admin/manage-option   — Read/write/delete options
 *   wp-admin/search          — Unified search across all content types
 *   wp-admin/get-post-types  — List post types with REST bases
 *   wp-admin/get-taxonomies  — List taxonomies with REST bases
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('wp_abilities_api_init', static function (): void {

    $require_admin = static fn(): bool => current_user_can('manage_options');

    // ── wp-admin/manage-option ────────────────────────────────────────────
    wp_register_ability('wp-admin/manage-option', [
        'label'       => 'Manage WordPress Option',
        'description' => 'Read (get), write (update), or delete a WordPress option. Common options: blogname, blogdescription, siteurl, show_on_front, page_on_front, permalink_structure…',
        'category'            => 'wp-admin',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            $action = $input['action'] ?? 'get';
            $name   = $input['name'] ?? '';

            if ($name === '') {
                return ['success' => false, 'error' => 'Option name is required.'];
            }

            switch ($action) {
                case 'get':
                    $value  = get_option($name, null);
                    $exists = $value !== null || get_option($name, '__not_found__') !== '__not_found__';

                    return ['success' => true, 'exists' => $exists, 'name' => $name, 'value' => $value];

                case 'update':
                    if (! array_key_exists('value', $input)) {
                        return ['success' => false, 'error' => '"value" is required for the update action.'];
                    }
                    update_option($name, $input['value']);

                    return ['success' => true, 'name' => $name, 'value' => get_option($name)];

                case 'delete':
                    $deleted = delete_option($name);

                    return ['success' => $deleted, 'deleted' => $deleted, 'name' => $name];

                default:
                    return ['success' => false, 'error' => "Unknown action: {$action}. Use get, update, or delete."];
            }
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['get', 'update', 'delete'], 'default' => 'get'],
                'name'   => ['type' => 'string', 'description' => 'Option name.'],
                'value'  => ['description' => 'Value to set (required for "update").'],
            ],
            'required'             => ['name'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'exists'  => ['type' => 'boolean'],
                'deleted' => ['type' => 'boolean'],
                'name'    => ['type' => 'string'],
                'value'   => ['description' => 'The option value.'],
                'error'   => ['type' => 'string'],
            ],
            'required' => ['success'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        ],
    ]);

    // ── wp-admin/search ───────────────────────────────────────────────────
    wp_register_ability('wp-admin/search', [
        'label'       => 'Search WordPress Content',
        'description' => 'Search across posts, pages, media, terms, and users. Filter by type (post, term, post-format) and subtype (post, page, category, tag…).',
        'category'            => 'wp-admin',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            rest_get_server();
            $request = new WP_REST_Request('GET', '/wp/v2/search');
            $request->set_query_params(array_filter([
                'search'   => $input['query'] ?? '',
                'per_page' => $input['per_page'] ?? 10,
                'page'     => $input['page'] ?? 1,
                'type'     => $input['type'] ?? '',
                'subtype'  => $input['subtype'] ?? '',
            ], static fn($v) => $v !== '' && $v !== null));

            $response = rest_do_request($request);
            $headers  = $response->get_headers();

            return [
                'results'     => $response->get_data(),
                'total'       => (int) ($headers['X-WP-Total'] ?? 0),
                'total_pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
            ];
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'query'    => ['type' => 'string', 'description' => 'Search query.'],
                'type'     => ['type' => 'string', 'enum' => ['post', 'term', 'post-format', ''], 'default' => ''],
                'subtype'  => ['type' => 'string', 'default' => ''],
                'per_page' => ['type' => 'integer', 'default' => 10],
                'page'     => ['type' => 'integer', 'default' => 1],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'results'     => ['type' => 'array'],
                'total'       => ['type' => 'integer'],
                'total_pages' => ['type' => 'integer'],
            ],
            'required' => ['results', 'total', 'total_pages'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);

    // ── wp-admin/get-post-types ───────────────────────────────────────────
    wp_register_ability('wp-admin/get-post-types', [
        'label'       => 'Get Post Types',
        'description' => 'Lists all registered post types with their REST base, labels, and supported features. Use rest_base with wp-rest/request.',
        'category'            => 'wp-admin',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (): array {
            $result = [];
            foreach (get_post_types([], 'objects') as $type) {
                $result[] = [
                    'name'         => $type->name,
                    'label'        => $type->label,
                    'public'       => $type->public,
                    'hierarchical' => $type->hierarchical,
                    'show_in_rest' => (bool) $type->show_in_rest,
                    'rest_base'    => $type->rest_base ?: null,
                    'supports'     => get_all_post_type_supports($type->name),
                    'taxonomies'   => get_object_taxonomies($type->name),
                ];
            }

            return ['post_types' => $result, 'total' => count($result)];
        },
        'input_schema'  => [],
        'output_schema' => ['type' => 'object', 'properties' => ['post_types' => ['type' => 'array'], 'total' => ['type' => 'integer']], 'required' => ['post_types', 'total']],
        'meta'          => ['mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    // ── wp-admin/get-taxonomies ───────────────────────────────────────────
    wp_register_ability('wp-admin/get-taxonomies', [
        'label'       => 'Get Taxonomies',
        'description' => 'Lists all registered taxonomies with their REST base and associated post types.',
        'category'            => 'wp-admin',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (): array {
            $result = [];
            foreach (get_taxonomies([], 'objects') as $tax) {
                $result[] = [
                    'name'         => $tax->name,
                    'label'        => $tax->label,
                    'public'       => $tax->public,
                    'hierarchical' => $tax->hierarchical,
                    'show_in_rest' => (bool) $tax->show_in_rest,
                    'rest_base'    => $tax->rest_base ?: null,
                    'object_types' => $tax->object_type,
                ];
            }

            return ['taxonomies' => $result, 'total' => count($result)];
        },
        'input_schema'  => [],
        'output_schema' => ['type' => 'object', 'properties' => ['taxonomies' => ['type' => 'array'], 'total' => ['type' => 'integer']], 'required' => ['taxonomies', 'total']],
        'meta'          => ['mcp' => ['public' => true, 'type' => 'tool'], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);
});
