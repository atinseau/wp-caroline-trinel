<?php

/**
 * Filesystem abilities
 *
 *   wp-filesystem/read-file  — Read a file relative to the active theme, wp-content, or ABSPATH
 *   wp-filesystem/write-file — Write a file within the active theme or wp-content (root is blocked)
 *
 * Both abilities use a shared path resolver that normalises the path and
 * prevents path-traversal attacks (no .. escaping the declared base).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

add_action('wp_abilities_api_init', static function (): void {

    $require_admin = static fn(): bool => current_user_can('manage_options');

    /**
     * Resolve a (base, relative-path) pair to a safe absolute filesystem path.
     *
     * Supported bases:
     *   theme        → active child theme  (get_stylesheet_directory())
     *   parent-theme → parent theme        (get_template_directory())
     *   content      → wp-content          (WP_CONTENT_DIR)
     *   root         → WordPress root      (ABSPATH)
     *
     * @return array{ok: bool, path: string, error?: string}
     */
    $resolve_path = static function (string $base, string $path): array {
        $bases = [
            'theme'        => rtrim(get_stylesheet_directory(), '/'),
            'parent-theme' => rtrim(get_template_directory(), '/'),
            'content'      => rtrim(WP_CONTENT_DIR, '/'),
            'root'         => rtrim(ABSPATH, '/'),
        ];

        if (! isset($bases[$base])) {
            return ['ok' => false, 'path' => '', 'error' => "Unknown base '{$base}'. Use: theme, parent-theme, content, root."];
        }

        $base_dir = $bases[$base];

        // Normalise without resolving symlinks (target may not exist yet for writes).
        $parts      = explode('/', $base_dir . '/' . ltrim($path, '/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($normalized) : ($normalized[] = $part);
        }
        $resolved = '/' . implode('/', $normalized);

        if (! str_starts_with($resolved, $base_dir)) {
            return ['ok' => false, 'path' => $resolved, 'error' => 'Path traversal detected — resolved path escapes the declared base.'];
        }

        return ['ok' => true, 'path' => $resolved];
    };

    // ── wp-filesystem/read-file ───────────────────────────────────────────
    wp_register_ability('wp-filesystem/read-file', [
        'label'       => 'Read WordPress Filesystem File',
        'description' => implode(' ', [
            'Read the raw contents of any file relative to the active child theme, parent theme, wp-content, or WordPress root.',
            'Use base="theme" for child theme files (theme.json, parts/header.html, style.css…).',
            'The path must not escape its base (no .. traversal).',
        ]),
        'category'            => 'wp-filesystem',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input) use ($resolve_path): array {
            $resolved = $resolve_path($input['base'] ?? 'theme', $input['path'] ?? '');

            if (! $resolved['ok']) {
                return ['exists' => false, 'content' => null, 'path' => $input['path'] ?? '', 'error' => $resolved['error']];
            }

            $abs = $resolved['path'];

            if (! file_exists($abs)) {
                return ['exists' => false, 'content' => null, 'path' => $abs];
            }
            if (! is_file($abs)) {
                return ['exists' => true, 'content' => null, 'path' => $abs, 'error' => 'Path is a directory, not a file.'];
            }
            if (! is_readable($abs)) {
                return ['exists' => true, 'content' => null, 'path' => $abs, 'error' => 'File is not readable.'];
            }

            return ['exists' => true, 'content' => file_get_contents($abs), 'path' => $abs, 'size' => filesize($abs)];
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'base' => ['type' => 'string', 'enum' => ['theme', 'parent-theme', 'content', 'root'], 'default' => 'theme', 'description' => '"theme"=child theme, "parent-theme"=parent theme, "content"=wp-content, "root"=ABSPATH.'],
                'path' => ['type' => 'string', 'description' => 'Relative path. Example: "parts/header.html", "theme.json".'],
            ],
            'required'             => ['path'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'exists'  => ['type' => 'boolean'],
                'content' => ['type' => ['string', 'null']],
                'path'    => ['type' => 'string', 'description' => 'Resolved absolute path.'],
                'size'    => ['type' => 'integer'],
                'error'   => ['type' => 'string'],
            ],
            'required' => ['exists'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);

    // ── wp-filesystem/write-file ──────────────────────────────────────────
    wp_register_ability('wp-filesystem/write-file', [
        'label'       => 'Write WordPress Filesystem File',
        'description' => implode(' ', [
            'Write content to a file within the active child theme, parent theme, or wp-content.',
            'Writes to "root" (WordPress core) are blocked for safety.',
            'Missing parent directories are created automatically (create_dirs=true by default).',
            'Overwrites existing content — read first with wp-filesystem/read-file if you need to preserve it.',
        ]),
        'category'            => 'wp-filesystem',
        'permission_callback' => $require_admin,
        'execute_callback'    => static function (array $input) use ($resolve_path): array {
            $base = $input['base'] ?? 'theme';

            if ($base === 'root') {
                return ['success' => false, 'error' => 'Writes to base="root" are not allowed. Use "theme", "parent-theme", or "content".'];
            }

            $resolved = $resolve_path($base, $input['path'] ?? '');
            if (! $resolved['ok']) {
                return ['success' => false, 'path' => $input['path'] ?? '', 'error' => $resolved['error']];
            }

            $abs = $resolved['path'];
            $dir = dirname($abs);

            if (! is_dir($dir)) {
                if (! ($input['create_dirs'] ?? true)) {
                    return ['success' => false, 'path' => $abs, 'error' => "Directory does not exist: {$dir}. Set create_dirs=true to create it."];
                }
                if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                    return ['success' => false, 'path' => $abs, 'error' => "Failed to create directory: {$dir}"];
                }
            }

            $bytes = file_put_contents($abs, $input['content'] ?? '');

            if ($bytes === false) {
                return ['success' => false, 'path' => $abs, 'error' => "Failed to write. Check permissions on: {$dir}"];
            }

            return ['success' => true, 'path' => $abs, 'bytes' => $bytes];
        },
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'base'        => ['type' => 'string', 'enum' => ['theme', 'parent-theme', 'content'], 'default' => 'theme', 'description' => '"theme"=child theme, "parent-theme"=parent theme, "content"=wp-content. "root" is blocked.'],
                'path'        => ['type' => 'string', 'description' => 'Relative path. Example: "parts/header.html".'],
                'content'     => ['type' => 'string', 'description' => 'Content to write. Overwrites existing content.'],
                'create_dirs' => ['type' => 'boolean', 'default' => true, 'description' => 'Create missing parent directories.'],
            ],
            'required'             => ['path', 'content'],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'path'    => ['type' => 'string'],
                'bytes'   => ['type' => 'integer'],
                'error'   => ['type' => 'string'],
            ],
            'required' => ['success'],
        ],
        'meta' => [
            'mcp'         => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
        ],
    ]);
});
