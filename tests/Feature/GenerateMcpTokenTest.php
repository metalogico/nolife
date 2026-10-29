<?php

use Illuminate\Support\Facades\Artisan;

it('generates a one-time bearer token with a matching configuration hash', function (string $access): void {
    $before = is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null;
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('nolife:mcp-token', ['--access' => $access]))->toBe(0);
    $output = Artisan::output();
    preg_match('/nolife_mcp_[a-f0-9]{64}/', $output, $matches);
    expect($matches)->toHaveCount(1);
    expect($output)->toContain('NOLIFE_MCP_'.strtoupper($access).'_TOKEN_HASH='.hash('sha256', $matches[0]));
    expect(substr_count($output, $matches[0]))->toBe(1);
    expect(is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null)->toBe($before);
})->with(['read', 'write']);

it('rejects unsupported token access without printing a bearer token', function (): void {
    $this->withoutMockingConsoleOutput();
    expect(Artisan::call('nolife:mcp-token', ['--access' => 'admin']))->toBe(1);

    expect(Artisan::output())->toContain('Access must be read or write.')->not->toContain('nolife_mcp_');
});
