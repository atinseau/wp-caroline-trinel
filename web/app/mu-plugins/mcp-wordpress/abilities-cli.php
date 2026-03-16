<?php

/**
 * WP-CLI & PHP execution abilities
 *
 *   wp-cli/execute — Run any WP-CLI command (cache, rewrite, media import, db, …)
 *   wp-php/eval    — Execute a PHP script inside the full WordPress context
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('wp_abilities_api_init', static function (): void {

    $require_admin = static fn(): bool => current_user_can('manage_options');

    // ── wp-cli/execute ────────────────────────────────────────────────────
    wp_register_ability('wp-cli/execute', [
        'label'       => 'Execute WP-CLI Command',
        'description' => implode(' ', [
            'Execute a WP-CLI command inside the WordPress environment.',
            'Covers operations not available via REST: cache, cron, rewrite, search-replace, import/export, db queries…',
            'Do NOT include the "wp" prefix — just the subcommand and arguments.',
            'Examples: "cache flush", "rewrite flush", "media import /path/to/img.jpg", "post list --format=json".',
        ]),
        'category'            => 'wp-cli',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            $command = trim($input['command'] ?? '');

            if ($command === '') {
                return new WP_Error('empty_command', 'The command string cannot be empty.');
            }
            if (! class_exists('WP_CLI')) {
                return ['success' => false, 'exit_code' => 1, 'output' => '', 'error' => 'WP-CLI is not available.'];
            }

            try {
                $result   = WP_CLI::runcommand($command, ['return' => 'all', 'parse' => false, 'launch' => false, 'exit_error' => false]);
                $response = ['success' => ($result->return_code ?? 0) === 0, 'exit_code' => $result->return_code ?? 0, 'output' => $result->stdout ?? ''];
                if (($result->stderr ?? '') !== '') {
                    $response['error'] = $result->stderr;
                }

                return $response;
            } catch (\Throwable $e) {
                return ['success' => false, 'exit_code' => $e->getCode() ?: 1, 'output' => '', 'error' => $e->getMessage()];
            }
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'WP-CLI command without the "wp" prefix. Example: "cache flush".'],
            ],
            'required'             => ['command'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success'   => ['type' => 'boolean'],
                'exit_code' => ['type' => 'integer'],
                'output'    => ['type' => 'string'],
                'error'     => ['type' => 'string'],
            ],
            'required' => ['success', 'exit_code', 'output'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        ],
    ]);

    // ── wp-php/eval ───────────────────────────────────────────────────────
    wp_register_ability('wp-php/eval', [
        'label'       => 'Execute PHP Code',
        'description' => implode(' ', [
            'Execute arbitrary PHP code inside the fully-loaded WordPress environment ($wpdb, all WP functions, plugins, themes).',
            'Code is written to a temp file and run via WP-CLI eval-file in an isolated subprocess,',
            'so exit()/die() calls and fatal errors cannot crash the MCP server.',
            'Use echo/print to produce output — captured and returned in "output".',
            'The <?php tag is optional (added automatically if missing).',
            'Ideal for bulk operations, data migrations, or multi-step tasks too large for a single WP-CLI command.',
        ]),
        'category'            => 'wp-cli',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input): array {
            $code = $input['code'] ?? '';

            if (trim($code) === '') {
                return ['success' => false, 'output' => '', 'error' => 'Code cannot be empty.'];
            }
            if (! class_exists('WP_CLI')) {
                return ['success' => false, 'output' => '', 'error' => 'WP-CLI is not available.'];
            }

            if (! str_starts_with(ltrim($code), '<?')) {
                $code = "<?php\n" . $code;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'mcp_eval_');
            if ($tmp === false) {
                return ['success' => false, 'output' => '', 'error' => 'Failed to create temporary file.'];
            }

            try {
                file_put_contents($tmp, $code);
                $result    = WP_CLI::runcommand('eval-file ' . $tmp, ['return' => 'all', 'parse' => false, 'launch' => true, 'exit_error' => false]);
                $exit_code = $result->return_code ?? 0;
                $stderr    = $result->stderr ?? '';

                return [
                    'success'   => $exit_code === 0,
                    'output'    => $result->stdout ?? '',
                    'exit_code' => $exit_code,
                    'error'     => $stderr !== '' ? $stderr : null,
                ];
            } catch (\Throwable $e) {
                return ['success' => false, 'output' => '', 'exit_code' => $e->getCode() ?: 1, 'error' => $e->getMessage()];
            } finally {
                @unlink($tmp);
            }
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'code' => ['type' => 'string', 'description' => 'PHP code to execute. <?php tag is optional. Use echo/print for output.'],
            ],
            'required'             => ['code'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success'   => ['type' => 'boolean'],
                'output'    => ['type' => 'string', 'description' => 'Captured stdout (echo/print output).'],
                'exit_code' => ['type' => 'integer'],
                'error'     => ['type' => ['string', 'null']],
            ],
            'required' => ['success', 'output'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
        ],
    ]);
});
