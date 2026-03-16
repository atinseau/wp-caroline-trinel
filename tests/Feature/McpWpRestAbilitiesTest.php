<?php

use Symfony\Component\Process\Process;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function mcpSend(array ...$messages): array
{
    $input = implode("\n", array_map(
        fn(array $msg) => json_encode($msg),
        $messages,
    )) . "\n";

    $process = new Process([
        'wp', '--allow-root',
        'mcp-adapter', 'serve',
        '--server=mcp-adapter-default-server',
        '--user=admin',
    ]);
    $process->setInput($input);
    $process->setTimeout(30);
    $process->run();

    if (! $process->isSuccessful()) {
        $stderr = implode("\n", array_filter(
            explode("\n", $process->getErrorOutput()),
            fn($l) => $l !== '' && ! str_starts_with($l, 'Debug'),
        ));
        if ($stderr !== '' && $process->getExitCode() !== 0) {
            throw new RuntimeException("MCP process failed (exit {$process->getExitCode()}): {$stderr}");
        }
    }

    $output = trim($process->getOutput());
    if ($output === '') {
        return [];
    }

    return array_values(array_map(
        fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $output), fn($l) => $l !== ''),
    ));
}

function init(int $id = 1): array
{
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'method'  => 'initialize',
        'params'  => [
            'protocolVersion' => '2024-11-05',
            'clientInfo'      => ['name' => 'pest-test', 'version' => '1.0'],
            'capabilities'    => new stdClass(),
        ],
    ];
}

function callTool(string $name, array $arguments = [], int $id = 2): array
{
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'method'  => 'tools/call',
        'params'  => [
            'name'      => $name,
            'arguments' => (object) $arguments,
        ],
    ];
}

/**
 * Call an MCP tool directly and return the parsed structured content.
 *
 * For the three meta-tools (discover / get-info / execute) the structured
 * content IS the final payload.  For execute-ability the payload is wrapped
 * in {success, data} by the adapter.
 */
function callToolParsed(string $toolName, array $arguments = []): array
{
    $responses = mcpSend(
        init(1),
        callTool($toolName, $arguments, 2),
    );

    expect($responses)->toHaveCount(2);
    expect($responses[1])->toHaveKey('result');
    expect($responses[1]['result'])->toHaveKey('content');

    $content = $responses[1]['result']['content'][0];

    // If the server flagged an error, return a recognisable envelope.
    if (! empty($responses[1]['result']['isError'])) {
        return ['_mcp_error' => true, 'message' => $content['text'] ?? ''];
    }

    return json_decode($content['text'], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Execute a WordPress ability through mcp-adapter-execute-ability and return
 * the inner data payload (i.e. what the ability callback returned).
 */
function executeAbility(string $abilityName, array $parameters = []): array
{
    $outer = callToolParsed('mcp-adapter-execute-ability', [
        'ability_name' => $abilityName,
        'parameters'   => (object) $parameters,
    ]);

    // The execute-ability wrapper returns {success: bool, data: <ability result>}.
    expect($outer)->toHaveKey('success', true);
    expect($outer)->toHaveKey('data');

    return $outer['data'];
}

/**
 * Shortcut: execute wp-rest/request and return the REST envelope {status, data, ?meta}.
 */
function restRequest(string $method, string $route, array $params = []): array
{
    return executeAbility('wp-rest/request', [
        'method' => $method,
        'route'  => $route,
        'params' => (object) $params,
    ]);
}

// ─── Discovery ────────────────────────────────────────────────────────────────

describe('Ability discovery', function () {

    test('all custom abilities appear in MCP discover-abilities', function () {
        // Call the discover tool directly (it is NOT mcp.public so we must NOT
        // go through execute-ability).
        $result = callToolParsed('mcp-adapter-discover-abilities');

        expect($result)->toHaveKey('abilities');

        $names = array_column($result['abilities'], 'name');

        expect($names)
            ->toContain('wp-rest/request')
            ->toContain('wp-rest/list-routes')
            ->toContain('wp-cli/execute')
            ->toContain('wp-admin/manage-option')
            ->toContain('wp-admin/search')
            ->toContain('wp-admin/get-post-types')
            ->toContain('wp-admin/get-taxonomies');
    });

    test('all custom abilities are registered as MCP tools', function () {
        $responses = mcpSend(
            init(1),
            [
                'jsonrpc' => '2.0',
                'id'      => 2,
                'method'  => 'tools/list',
                'params'  => new stdClass(),
            ],
        );

        expect($responses)->toHaveCount(2);
        expect($responses[1]['result'])->toHaveKey('tools');

        $toolNames = array_column($responses[1]['result']['tools'], 'name');

        // The 3 meta-tools from the adapter must always be present.
        expect($toolNames)
            ->toContain('mcp-adapter-discover-abilities')
            ->toContain('mcp-adapter-get-ability-info')
            ->toContain('mcp-adapter-execute-ability');

        // Our custom abilities must appear as direct MCP tools (not only
        // behind discover → execute indirection) so the model sees them
        // in tools/list and understands it can create/modify/delete content.
        expect($toolNames)
            ->toContain('wp-rest-request')
            ->toContain('wp-rest-list-routes')
            ->toContain('wp-cli-execute')
            ->toContain('wp-admin-manage-option')
            ->toContain('wp-admin-search')
            ->toContain('wp-admin-get-post-types')
            ->toContain('wp-admin-get-taxonomies');
    });

    test('each custom ability can be introspected via get-ability-info', function () {
        $abilities = [
            'wp-rest/request',
            'wp-rest/list-routes',
            'wp-cli/execute',
            'wp-admin/manage-option',
            'wp-admin/search',
            'wp-admin/get-post-types',
            'wp-admin/get-taxonomies',
        ];

        foreach ($abilities as $name) {
            $result = callToolParsed('mcp-adapter-get-ability-info', [
                'ability_name' => $name,
            ]);

            expect($result)
                ->toHaveKey('name', $name)
                ->toHaveKey('label')
                ->toHaveKey('description');

            expect($result['label'])->toBeString()->not->toBeEmpty();
            expect($result['description'])->toBeString()->not->toBeEmpty();
        }
    });

});

// ─── wp-rest/request ──────────────────────────────────────────────────────────

describe('wp-rest/request', function () {

    test('can GET posts', function () {
        $envelope = restRequest('GET', '/wp/v2/posts', ['per_page' => 1]);

        expect($envelope['status'])->toBe(200);
        expect($envelope['data'])->toBeArray();
    });

    test('can create, read, update and delete a page', function () {
        // CREATE
        $create = restRequest('POST', '/wp/v2/pages', [
            'title'   => 'Pest CRUD Test',
            'content' => 'Created by Pest.',
            'status'  => 'draft',
        ]);

        expect($create['status'])->toBe(201);
        expect($create['data'])->toHaveKey('id');

        $pageId = $create['data']['id'];

        expect($create['data']['title']['raw'])->toBe('Pest CRUD Test');

        // READ
        $read = restRequest('GET', "/wp/v2/pages/{$pageId}");

        expect($read['status'])->toBe(200);
        expect($read['data']['id'])->toBe($pageId);
        expect($read['data']['title']['rendered'])->toBe('Pest CRUD Test');

        // UPDATE
        $update = restRequest('PUT', "/wp/v2/pages/{$pageId}", [
            'title' => 'Pest CRUD Updated',
        ]);

        expect($update['status'])->toBe(200);
        expect($update['data']['title']['raw'])->toBe('Pest CRUD Updated');

        // DELETE (bypass trash)
        $delete = restRequest('DELETE', "/wp/v2/pages/{$pageId}", [
            'force' => true,
        ]);

        expect($delete['status'])->toBe(200);
        expect($delete['data']['deleted'])->toBeTrue();

        // Confirm gone
        $gone = restRequest('GET', "/wp/v2/pages/{$pageId}");

        expect($gone['status'])->toBe(404);
    });

    test('can create and delete a post with categories and tags', function () {
        // Create a category first.
        $cat = restRequest('POST', '/wp/v2/categories', ['name' => 'Pest Category']);
        expect($cat['status'])->toBe(201);
        $catId = $cat['data']['id'];

        // Create a tag.
        $tag = restRequest('POST', '/wp/v2/tags', ['name' => 'Pest Tag']);
        expect($tag['status'])->toBe(201);
        $tagId = $tag['data']['id'];

        // Create post with taxonomy terms.
        $post = restRequest('POST', '/wp/v2/posts', [
            'title'      => 'Pest Taxonomy Test',
            'status'     => 'draft',
            'categories' => [$catId],
            'tags'       => [$tagId],
        ]);

        expect($post['status'])->toBe(201);
        expect($post['data']['categories'])->toContain($catId);
        expect($post['data']['tags'])->toContain($tagId);

        $postId = $post['data']['id'];

        // Cleanup
        restRequest('DELETE', "/wp/v2/posts/{$postId}", ['force' => true]);
        restRequest('DELETE', "/wp/v2/tags/{$tagId}", ['force' => true]);
        restRequest('DELETE', "/wp/v2/categories/{$catId}", ['force' => true]);
    });

    test('returns pagination metadata', function () {
        $envelope = restRequest('GET', '/wp/v2/posts', ['per_page' => 1]);

        expect($envelope['status'])->toBe(200);
        // Even with 1 post, the meta keys should exist.
        expect($envelope)->toHaveKey('meta');
        expect($envelope['meta'])->toHaveKey('total');
        expect($envelope['meta'])->toHaveKey('total_pages');
        expect($envelope['meta']['total'])->toBeGreaterThanOrEqual(1);
    });

    test('can read and update site settings', function () {
        // Read settings
        $read = restRequest('GET', '/wp/v2/settings');
        expect($read['status'])->toBe(200);
        expect($read['data'])->toHaveKey('title');

        $originalTitle = $read['data']['title'];

        // Update title
        $update = restRequest('POST', '/wp/v2/settings', [
            'title' => 'Pest Settings Test',
        ]);
        expect($update['status'])->toBe(200);
        expect($update['data']['title'])->toBe('Pest Settings Test');

        // Restore
        restRequest('POST', '/wp/v2/settings', [
            'title' => $originalTitle,
        ]);
    });

    test('returns 404 for non-existent route', function () {
        $envelope = restRequest('GET', '/wp/v2/nonexistent-endpoint');

        expect($envelope['status'])->toBe(404);
    });

    test('returns error for invalid method on existing route', function () {
        // /wp/v2/settings does not support DELETE
        $envelope = restRequest('DELETE', '/wp/v2/settings');

        expect($envelope['status'])->toBeGreaterThanOrEqual(400);
    });

    test('can GET users', function () {
        $envelope = restRequest('GET', '/wp/v2/users');

        expect($envelope['status'])->toBe(200);
        expect($envelope['data'])->toBeArray()->not->toBeEmpty();
        expect($envelope['data'][0])->toHaveKey('id');
        expect($envelope['data'][0])->toHaveKey('name');
    });

    test('can GET comments', function () {
        $envelope = restRequest('GET', '/wp/v2/comments');

        expect($envelope['status'])->toBe(200);
        expect($envelope['data'])->toBeArray();
    });

});

// ─── wp-rest/list-routes ──────────────────────────────────────────────────────

describe('wp-rest/list-routes', function () {

    test('returns all routes without filters', function () {
        $result = executeAbility('wp-rest/list-routes');

        expect($result)->toHaveKey('routes');
        expect($result)->toHaveKey('total');
        expect($result['routes'])->toBeArray()->not->toBeEmpty();
        expect($result['total'])->toBeGreaterThan(0);

        // Each route should have route + methods keys.
        $first = $result['routes'][0];
        expect($first)->toHaveKey('route');
        expect($first)->toHaveKey('methods');
        expect($first['methods'])->toBeArray();
    });

    test('can filter routes by namespace', function () {
        $result = executeAbility('wp-rest/list-routes', [
            'namespace' => 'wp/v2',
        ]);

        expect($result['routes'])->not->toBeEmpty();

        // All returned routes should start with /wp/v2
        foreach ($result['routes'] as $route) {
            expect($route['route'])->toStartWith('/wp/v2');
        }
    });

    test('can filter routes by search string', function () {
        $result = executeAbility('wp-rest/list-routes', [
            'search' => 'posts',
        ]);

        expect($result['routes'])->not->toBeEmpty();

        foreach ($result['routes'] as $route) {
            expect(strtolower($route['route']))->toContain('posts');
        }
    });

    test('returns empty array for non-matching filter', function () {
        $result = executeAbility('wp-rest/list-routes', [
            'search' => 'zzz-nonexistent-route-zzz',
        ]);

        expect($result['routes'])->toBeArray()->toBeEmpty();
        expect($result['total'])->toBe(0);
    });

});

// ─── wp-cli/execute ───────────────────────────────────────────────────────────

describe('wp-cli/execute', function () {

    test('can run a simple wp-cli command', function () {
        $result = executeAbility('wp-cli/execute', [
            'command' => 'core version',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['exit_code'])->toBe(0);
        expect($result['output'])->toMatch('/^\d+\.\d+/');
    });

    test('can list posts as json', function () {
        $result = executeAbility('wp-cli/execute', [
            'command' => 'post list --post_type=post --format=json',
        ]);

        expect($result['success'])->toBeTrue();

        $posts = json_decode($result['output'], true);

        expect($posts)->toBeArray();
    });

    test('can get an option value', function () {
        $result = executeAbility('wp-cli/execute', [
            'command' => 'option get blogname',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['output'])->toBeString()->not->toBeEmpty();
    });

    test('handles failing command gracefully', function () {
        // Use a command that genuinely fails: getting a non-existent post
        $result = executeAbility('wp-cli/execute', [
            'command' => 'post get 999999 --format=json',
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['exit_code'])->toBeGreaterThan(0);
        expect($result)->toHaveKey('error');
        expect($result['error'])->toBeString()->not->toBeEmpty();
    });

});

// ─── wp-admin/manage-option ───────────────────────────────────────────────────

describe('wp-admin/manage-option', function () {

    test('can read an existing option', function () {
        $result = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => 'blogname',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['exists'])->toBeTrue();
        expect($result['name'])->toBe('blogname');
        expect($result['value'])->toBeString()->not->toBeEmpty();
    });

    test('reports non-existent option correctly', function () {
        $result = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => 'pest_nonexistent_option_xyz_123',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['exists'])->toBeFalse();
    });

    test('can create, update, and delete an option', function () {
        $optionName = 'pest_test_option_' . time();

        // Create
        $create = executeAbility('wp-admin/manage-option', [
            'action' => 'update',
            'name'   => $optionName,
            'value'  => 'hello from pest',
        ]);

        expect($create['success'])->toBeTrue();
        expect($create['value'])->toBe('hello from pest');

        // Read it back
        $read = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => $optionName,
        ]);

        expect($read['exists'])->toBeTrue();
        expect($read['value'])->toBe('hello from pest');

        // Update
        $update = executeAbility('wp-admin/manage-option', [
            'action' => 'update',
            'name'   => $optionName,
            'value'  => 'updated value',
        ]);

        expect($update['success'])->toBeTrue();
        expect($update['value'])->toBe('updated value');

        // Delete
        $delete = executeAbility('wp-admin/manage-option', [
            'action' => 'delete',
            'name'   => $optionName,
        ]);

        expect($delete['success'])->toBeTrue();
        expect($delete['deleted'])->toBeTrue();

        // Confirm gone
        $gone = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => $optionName,
        ]);

        expect($gone['exists'])->toBeFalse();
    });

    test('can store complex values (arrays)', function () {
        $optionName = 'pest_complex_option_' . time();
        $complex    = ['key1' => 'value1', 'nested' => ['a' => 1, 'b' => 2]];

        $create = executeAbility('wp-admin/manage-option', [
            'action' => 'update',
            'name'   => $optionName,
            'value'  => $complex,
        ]);

        expect($create['success'])->toBeTrue();

        $read = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => $optionName,
        ]);

        expect($read['value'])->toBe($complex);

        // Cleanup
        executeAbility('wp-admin/manage-option', [
            'action' => 'delete',
            'name'   => $optionName,
        ]);
    });

    test('input schema rejects unknown action', function () {
        // The "action" field has an enum constraint so the MCP adapter
        // rejects "purge" at schema-validation level before reaching our
        // callback.  The response is an isError text, not valid JSON.
        $raw = callToolParsed('mcp-adapter-execute-ability', [
            'ability_name' => 'wp-admin/manage-option',
            'parameters'   => (object) ['action' => 'purge', 'name' => 'blogname'],
        ]);

        // The adapter returns an isError with a validation message about the enum.
        expect($raw)->toHaveKey('_mcp_error', true);
        expect($raw['message'])->toContain('is not one of');
    });

});

// ─── wp-admin/search ──────────────────────────────────────────────────────────

describe('wp-admin/search', function () {

    test('can search for existing content', function () {
        // The default "Hello world!" post should exist.
        $result = executeAbility('wp-admin/search', [
            'query' => 'Hello',
        ]);

        expect($result)->toHaveKey('results');
        expect($result)->toHaveKey('total');
        expect($result)->toHaveKey('total_pages');
        expect($result['results'])->toBeArray()->not->toBeEmpty();
    });

    test('returns empty results for non-matching query', function () {
        $result = executeAbility('wp-admin/search', [
            'query' => 'zzz_pest_nonexistent_content_xyz_' . time(),
        ]);

        expect($result['results'])->toBeArray()->toBeEmpty();
        expect($result['total'])->toBe(0);
    });

    test('can filter search by type', function () {
        $result = executeAbility('wp-admin/search', [
            'query' => 'Hello',
            'type'  => 'post',
        ]);

        expect($result['results'])->toBeArray();
        // All results should be of type "post".
        foreach ($result['results'] as $item) {
            expect($item['type'])->toBe('post');
        }
    });

    test('respects per_page parameter', function () {
        $result = executeAbility('wp-admin/search', [
            'query'    => 'Hello',
            'per_page' => 1,
        ]);

        expect(count($result['results']))->toBeLessThanOrEqual(1);
    });

});

// ─── wp-admin/get-post-types ──────────────────────────────────────────────────

describe('wp-admin/get-post-types', function () {

    test('returns registered post types', function () {
        $result = executeAbility('wp-admin/get-post-types');

        expect($result)->toHaveKey('post_types');
        expect($result)->toHaveKey('total');
        expect($result['post_types'])->toBeArray()->not->toBeEmpty();
        expect($result['total'])->toBeGreaterThan(0);
    });

    test('includes core post types with expected fields', function () {
        $result = executeAbility('wp-admin/get-post-types');

        $names = array_column($result['post_types'], 'name');

        expect($names)->toContain('post');
        expect($names)->toContain('page');
        expect($names)->toContain('attachment');

        // Find the "post" type and check structure.
        $postType = null;
        foreach ($result['post_types'] as $pt) {
            if ($pt['name'] === 'post') {
                $postType = $pt;
                break;
            }
        }

        expect($postType)->not->toBeNull();
        expect($postType)->toHaveKey('label');
        expect($postType)->toHaveKey('public');
        expect($postType)->toHaveKey('hierarchical');
        expect($postType)->toHaveKey('show_in_rest');
        expect($postType)->toHaveKey('rest_base', 'posts');
        expect($postType)->toHaveKey('supports');
        expect($postType)->toHaveKey('taxonomies');
        expect($postType['taxonomies'])->toContain('category');
        expect($postType['taxonomies'])->toContain('post_tag');
    });

    test('page post type is hierarchical', function () {
        $result = executeAbility('wp-admin/get-post-types');

        $pageType = null;
        foreach ($result['post_types'] as $pt) {
            if ($pt['name'] === 'page') {
                $pageType = $pt;
                break;
            }
        }

        expect($pageType)->not->toBeNull();
        expect($pageType['hierarchical'])->toBeTrue();
        expect($pageType['rest_base'])->toBe('pages');
    });

});

// ─── wp-admin/get-taxonomies ──────────────────────────────────────────────────

describe('wp-admin/get-taxonomies', function () {

    test('returns registered taxonomies', function () {
        $result = executeAbility('wp-admin/get-taxonomies');

        expect($result)->toHaveKey('taxonomies');
        expect($result)->toHaveKey('total');
        expect($result['taxonomies'])->toBeArray()->not->toBeEmpty();
        expect($result['total'])->toBeGreaterThan(0);
    });

    test('includes core taxonomies with expected fields', function () {
        $result = executeAbility('wp-admin/get-taxonomies');

        $names = array_column($result['taxonomies'], 'name');

        expect($names)->toContain('category');
        expect($names)->toContain('post_tag');

        // Check category structure
        $category = null;
        foreach ($result['taxonomies'] as $tax) {
            if ($tax['name'] === 'category') {
                $category = $tax;
                break;
            }
        }

        expect($category)->not->toBeNull();
        expect($category)->toHaveKey('label');
        expect($category)->toHaveKey('hierarchical', true);
        expect($category)->toHaveKey('show_in_rest', true);
        expect($category)->toHaveKey('rest_base', 'categories');
        expect($category)->toHaveKey('object_types');
        expect($category['object_types'])->toContain('post');
    });

    test('post_tag taxonomy is not hierarchical', function () {
        $result = executeAbility('wp-admin/get-taxonomies');

        $tag = null;
        foreach ($result['taxonomies'] as $tax) {
            if ($tax['name'] === 'post_tag') {
                $tag = $tax;
                break;
            }
        }

        expect($tag)->not->toBeNull();
        expect($tag['hierarchical'])->toBeFalse();
        expect($tag['rest_base'])->toBe('tags');
    });

});

// ─── Integration / end-to-end workflows ───────────────────────────────────────

describe('End-to-end workflows', function () {

    test('full content creation workflow: category → post → verify → cleanup', function () {
        // 1. Discover post types to find the REST base
        $types = executeAbility('wp-admin/get-post-types');
        $postRestBase = null;
        foreach ($types['post_types'] as $pt) {
            if ($pt['name'] === 'post') {
                $postRestBase = $pt['rest_base'];
                break;
            }
        }
        expect($postRestBase)->toBe('posts');

        // 2. Create a category
        $cat = restRequest('POST', '/wp/v2/categories', [
            'name'        => 'Pest Workflow Category',
            'description' => 'Created by end-to-end test',
        ]);
        expect($cat['status'])->toBe(201);
        $catId = $cat['data']['id'];

        // 3. Create a post in that category
        $post = restRequest('POST', '/wp/v2/posts', [
            'title'      => 'Pest Workflow Post',
            'content'    => '<!-- wp:paragraph --><p>Test content</p><!-- /wp:paragraph -->',
            'status'     => 'publish',
            'categories' => [$catId],
        ]);
        expect($post['status'])->toBe(201);
        $postId = $post['data']['id'];

        // 4. Search for the post
        $search = executeAbility('wp-admin/search', [
            'query' => 'Pest Workflow Post',
            'type'  => 'post',
        ]);
        $searchIds = array_column($search['results'], 'id');
        expect($searchIds)->toContain($postId);

        // 5. Verify via WP-CLI
        $cli = executeAbility('wp-cli/execute', [
            'command' => "post get {$postId} --field=title",
        ]);
        expect(trim($cli['output']))->toBe('Pest Workflow Post');

        // 6. Verify the blog name option has not changed (sanity check)
        $opt = executeAbility('wp-admin/manage-option', [
            'action' => 'get',
            'name'   => 'blogname',
        ]);
        expect($opt['success'])->toBeTrue();

        // 7. Cleanup
        restRequest('DELETE', "/wp/v2/posts/{$postId}", ['force' => true]);
        restRequest('DELETE', "/wp/v2/categories/{$catId}", ['force' => true]);

        // 8. Confirm post is gone
        $gone = restRequest('GET', "/wp/v2/posts/{$postId}");
        expect($gone['status'])->toBe(404);
    });

    test('can discover routes then use them dynamically', function () {
        // 1. Find routes containing "settings"
        $routes = executeAbility('wp-rest/list-routes', [
            'search' => 'settings',
        ]);

        expect($routes['routes'])->not->toBeEmpty();

        // 2. Find the /wp/v2/settings route
        $settingsRoute = null;
        foreach ($routes['routes'] as $r) {
            if ($r['route'] === '/wp/v2/settings') {
                $settingsRoute = $r;
                break;
            }
        }

        expect($settingsRoute)->not->toBeNull();
        expect($settingsRoute['methods'])->toContain('GET');

        // 3. Use the discovered route
        $settings = restRequest('GET', $settingsRoute['route']);
        expect($settings['status'])->toBe(200);
        expect($settings['data'])->toHaveKey('title');
    });

    test('page with parent hierarchy via REST', function () {
        // Create parent page.
        $parent = restRequest('POST', '/wp/v2/pages', [
            'title'  => 'Pest Parent Page',
            'status' => 'draft',
        ]);
        expect($parent['status'])->toBe(201);
        $parentId = $parent['data']['id'];

        // Create child page.
        $child = restRequest('POST', '/wp/v2/pages', [
            'title'  => 'Pest Child Page',
            'status' => 'draft',
            'parent' => $parentId,
        ]);
        expect($child['status'])->toBe(201);
        $childId = $child['data']['id'];

        expect($child['data']['parent'])->toBe($parentId);

        // Read child and verify parent.
        $read = restRequest('GET', "/wp/v2/pages/{$childId}");
        expect($read['data']['parent'])->toBe($parentId);

        // Cleanup (child first).
        restRequest('DELETE', "/wp/v2/pages/{$childId}", ['force' => true]);
        restRequest('DELETE', "/wp/v2/pages/{$parentId}", ['force' => true]);
    });

});
