<?php

/**
 * REST API abilities
 *
 *   wp-rest/request     — Universal proxy for any REST endpoint (GET/POST/PUT/PATCH/DELETE)
 *   wp-rest/list-routes — Discover all registered REST routes and their methods
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('wp_abilities_api_init', static function (): void {

    $require_admin = static fn(): bool => current_user_can('manage_options');

    // ── wp-rest/request ───────────────────────────────────────────────────
    wp_register_ability('wp-rest/request', [
        'label'       => 'REST API Request',
        'description' => implode(' ', [
            'Execute any WordPress REST API request (GET/POST/PUT/PATCH/DELETE).',
            'Use this to create/read/update/delete posts, pages, media, users, menus, templates, template-parts, settings, etc.',
            'Route must start with / (e.g. /wp/v2/pages). Params are query args for GET, body fields for writes.',
            'Pagination headers (X-WP-Total, X-WP-TotalPages) are returned in the "meta" field.',
            'Discover available routes with wp-rest/list-routes.',
        ]),
        'category'            => 'wp-rest',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            $method = strtoupper($input['method'] ?? 'GET');
            $route  = $input['route'] ?? '';
            $params = $input['params'] ?? [];

            rest_get_server();
            $request = new WP_REST_Request($method, $route);

            if ($method === 'GET') {
                foreach ($params as $key => $value) {
                    $request->set_query_params(array_merge($request->get_query_params(), [$key => $value]));
                }
            } else {
                $request->set_body_params($params);
                foreach ($params as $key => $value) {
                    $request->set_param($key, $value);
                }
            }

            $response = rest_do_request($request);
            $headers  = $response->get_headers();
            $result   = ['status' => $response->get_status(), 'data' => $response->get_data()];

            $meta = [];
            if (isset($headers['X-WP-Total'])) {
                $meta['total'] = (int) $headers['X-WP-Total'];
            }
            if (isset($headers['X-WP-TotalPages'])) {
                $meta['total_pages'] = (int) $headers['X-WP-TotalPages'];
            }
            if (! empty($meta)) {
                $result['meta'] = $meta;
            }

            return $result;
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'GET'],
                'route'  => ['type' => 'string', 'description' => 'REST route starting with /. Example: /wp/v2/pages/42'],
                'params' => ['type' => 'object', 'additionalProperties' => true, 'default' => new stdClass()],
            ],
            'required'             => ['route'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'status' => ['type' => 'integer'],
                'data'   => ['description' => 'Response data.'],
                'meta'   => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer'], 'total_pages' => ['type' => 'integer']]],
            ],
            'required' => ['status', 'data'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        ],
    ]);

    // ── wp-rest/list-routes ───────────────────────────────────────────────
    wp_register_ability('wp-rest/list-routes', [
        'label'       => 'List REST API Routes',
        'description' => 'Lists all registered WordPress REST API routes with their HTTP methods. Filter by namespace (e.g. "wp/v2") or a search substring.',
        'category'            => 'wp-rest',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            $routes           = rest_get_server()->get_routes();
            $namespace_filter = $input['namespace'] ?? '';
            $search_filter    = $input['search'] ?? '';
            $result           = [];

            foreach ($routes as $route => $handlers) {
                if ($namespace_filter !== '' && ! str_starts_with($route, '/' . $namespace_filter)) {
                    continue;
                }
                if ($search_filter !== '' && stripos($route, $search_filter) === false) {
                    continue;
                }

                $methods = [];
                foreach ($handlers as $handler) {
                    if (isset($handler['methods'])) {
                        $handler_methods = is_array($handler['methods'])
                            ? array_keys(array_filter($handler['methods']))
                            : explode(',', (string) $handler['methods']);
                        $methods = array_merge($methods, $handler_methods);
                    }
                }

                $result[] = ['route' => $route, 'methods' => array_values(array_unique($methods))];
            }

            return ['routes' => $result, 'total' => count($result)];
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'namespace' => ['type' => 'string', 'description' => 'Filter by namespace. Example: "wp/v2".', 'default' => ''],
                'search'    => ['type' => 'string', 'description' => 'Filter by substring. Example: "template-parts".', 'default' => ''],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'routes' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['route' => ['type' => 'string'], 'methods' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
                'total'  => ['type' => 'integer'],
            ],
            'required' => ['routes', 'total'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);
});
