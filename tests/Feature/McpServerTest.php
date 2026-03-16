<?php

use Symfony\Component\Process\Process;

// Helper: send JSON-RPC messages to the MCP STDIO server via WP-CLI directly.
// Tests run inside the Docker container, so we call wp-cli without docker compose.
function mcpRequest(string|array $messages): array
{
    if (is_string($messages)) {
        $messages = [$messages];
    }

    $input = implode("\n", array_map(
        fn ($msg) => is_array($msg) ? json_encode($msg) : $msg,
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
        $stderr = $process->getErrorOutput();
        // Filter out WP-CLI debug noise
        $filteredStderr = implode("\n", array_filter(
            explode("\n", $stderr),
            fn ($line) => $line !== '' && ! str_starts_with($line, 'Debug'),
        ));

        if ($filteredStderr !== '' && $process->getExitCode() !== 0) {
            throw new RuntimeException("MCP STDIO process failed (exit {$process->getExitCode()}): {$filteredStderr}");
        }
    }

    $output = trim($process->getOutput());

    if ($output === '') {
        return [];
    }

    return array_map(
        fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_filter(explode("\n", $output), fn ($line) => $line !== ''),
    );
}

function initializeMessage(int $id = 1): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => ['name' => 'pest-test', 'version' => '1.0'],
            'capabilities' => new stdClass,
        ],
    ];
}

function toolsListMessage(int $id = 2): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/list',
        'params' => new stdClass,
    ];
}

function toolCallMessage(string $name, array $arguments = [], int $id = 3): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => [
            'name' => $name,
            'arguments' => (object) $arguments,
        ],
    ];
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test('mcp server initializes and returns valid protocol version', function () {
    $responses = mcpRequest([initializeMessage()]);

    expect($responses)->toHaveCount(1);

    $response = $responses[0];

    expect($response)
        ->toHaveKey('jsonrpc', '2.0')
        ->toHaveKey('id', 1)
        ->toHaveKey('result');

    expect($response['result'])
        ->toHaveKey('protocolVersion')
        ->toHaveKey('serverInfo')
        ->toHaveKey('capabilities');

    expect($response['result']['serverInfo'])
        ->toHaveKey('name', 'MCP Adapter Default Server');

    expect($response['result']['capabilities'])
        ->toHaveKey('tools');
});

test('mcp server lists available tools', function () {
    $responses = mcpRequest([
        initializeMessage(1),
        toolsListMessage(2),
    ]);

    expect($responses)->toHaveCount(2);

    $toolsResponse = $responses[1];

    expect($toolsResponse)
        ->toHaveKey('jsonrpc', '2.0')
        ->toHaveKey('id', 2)
        ->toHaveKey('result');

    expect($toolsResponse['result'])->toHaveKey('tools');

    $tools = $toolsResponse['result']['tools'];
    $toolNames = array_column($tools, 'name');

    expect($toolNames)
        ->toContain('mcp-adapter-discover-abilities')
        ->toContain('mcp-adapter-get-ability-info')
        ->toContain('mcp-adapter-execute-ability');
});

test('mcp server tools have valid schemas', function () {
    $responses = mcpRequest([
        initializeMessage(1),
        toolsListMessage(2),
    ]);

    $tools = $responses[1]['result']['tools'];

    foreach ($tools as $tool) {
        expect($tool)
            ->toHaveKey('name')
            ->toHaveKey('description')
            ->toHaveKey('inputSchema');

        expect($tool['name'])->toBeString()->not->toBeEmpty();
        expect($tool['description'])->toBeString()->not->toBeEmpty();
        expect($tool['inputSchema'])->toBeArray()->toHaveKey('type', 'object');
    }
});

test('mcp server can discover wordpress abilities', function () {
    $responses = mcpRequest([
        initializeMessage(1),
        toolCallMessage('mcp-adapter-discover-abilities', [], 2),
    ]);

    expect($responses)->toHaveCount(2);

    $callResponse = $responses[1];

    expect($callResponse)
        ->toHaveKey('jsonrpc', '2.0')
        ->toHaveKey('id', 2)
        ->toHaveKey('result');

    expect($callResponse['result'])->toHaveKey('content');

    $content = $callResponse['result']['content'];

    expect($content)->toBeArray()->not->toBeEmpty();
    expect($content[0])->toHaveKey('type', 'text');
    expect($content[0])->toHaveKey('text');

    // The text content is JSON — decode and verify structure
    $data = json_decode($content[0]['text'], true);

    expect($data)->toBeArray()->toHaveKey('abilities');
    expect($data['abilities'])->toBeArray();
});

test('mcp server rejects unknown methods with error', function () {
    $responses = mcpRequest([
        initializeMessage(1),
        [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'nonexistent/method',
            'params' => new stdClass,
        ],
    ]);

    expect($responses)->toHaveCount(2);

    $errorResponse = $responses[1];

    expect($errorResponse)
        ->toHaveKey('jsonrpc', '2.0')
        ->toHaveKey('id', 2)
        ->toHaveKey('error');

    expect($errorResponse['error'])
        ->toHaveKey('code')
        ->toHaveKey('message');
});
