<?php

use App\Models\Game;
use App\Models\GameImage;
use App\Models\McpSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.url' => 'http://localhost',
        'nolife.username' => 'settings-user',
        'nolife.password_hash' => Hash::make('settings-test-password'),
        'nolife.mcp_enabled' => false,
        'nolife.mcp_read_token_hash' => '',
        'nolife.mcp_write_token_hash' => '',
    ]);
});

function settingsMcpProbe(string $token): TestResponse
{
    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Bearer '.$token,
    ]);
}

it('protects every MCP management operation with the browser account', function (string $method, string $url): void {
    $this->json($method, $url, ['enabled' => true])->assertUnauthorized();
})->with([
    ['GET', '/api/v1/settings/mcp'],
    ['PATCH', '/api/v1/settings/mcp'],
    ['POST', '/api/v1/settings/mcp/tokens/read'],
    ['DELETE', '/api/v1/settings/mcp/tokens/write'],
]);

it('does not accept an MCP bearer token as access to browser settings', function (): void {
    McpSetting::factory()->create(['enabled' => true, 'write_token_hash' => hash('sha256', 'agent-token')]);

    $this->withToken('agent-token')->getJson('/api/v1/settings/mcp')->assertUnauthorized();
});

it('shows disabled defaults and the endpoint without writing a settings row', function (): void {
    $this->withSession(personalSession())->getJson('/api/v1/settings/mcp')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('enabled', false)->assertJsonPath('url', 'http://localhost/mcp')
        ->assertJsonPath('keys.read.configured', false)->assertJsonPath('keys.write.configured', false);
    $this->assertDatabaseCount('mcp_settings', 0);
});

it('generates independent keys and returns each plaintext only once', function (string $access): void {
    $before = is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null;
    $response = $this->withSession(personalSession())->postJson('/api/v1/settings/mcp/tokens/'.$access)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('access', $access)->assertJsonPath('settings.keys.'.$access.'.configured', true);
    $token = $response->json('token');
    expect($token)->toStartWith('nolife_mcp_')->toHaveLength(75);
    $stored = McpSetting::query()->findOrFail(1);
    expect($stored->getAttribute($access.'_token_hash'))->toBe(hash('sha256', $token));
    expect($stored->getAttribute($access.'_token_generated_at'))->not->toBeNull();
    expect($stored->enabled)->toBeFalse();
    $other = $access === 'read' ? 'write' : 'read';
    expect($stored->getAttribute($other.'_token_hash'))->toBeNull();

    $status = $this->getJson('/api/v1/settings/mcp')->assertOk()->assertJsonMissingPath('token');
    expect(json_encode($status->json()))->not->toContain($token, hash('sha256', $token));
    expect($stored->toArray())->not->toHaveKey('read_token_hash')->not->toHaveKey('write_token_hash');
    expect(is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null)->toBe($before);
})->with(['read', 'write']);

it('enables and disables immediately while keeping generated keys', function (): void {
    $response = $this->withSession(personalSession())->postJson('/api/v1/settings/mcp/tokens/read')->assertOk();
    $token = $response->json('token');

    $this->patchJson('/api/v1/settings/mcp', ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
    settingsMcpProbe($token)->assertOk()->assertJsonCount(3, 'result.tools');
    $this->patchJson('/api/v1/settings/mcp', ['enabled' => false])->assertOk()->assertJsonPath('keys.read.configured', true);
    settingsMcpProbe($token)->assertNotFound();
    $this->patchJson('/api/v1/settings/mcp', ['enabled' => true])->assertOk();
    settingsMcpProbe($token)->assertOk();
    expect(config('nolife.mcp_enabled'))->toBeFalse();
});

it('rotates one key immediately without interrupting the other access level', function (): void {
    $read = $this->withSession(personalSession())->postJson('/api/v1/settings/mcp/tokens/read')->json('token');
    $write = $this->postJson('/api/v1/settings/mcp/tokens/write')->json('token');
    $this->patchJson('/api/v1/settings/mcp', ['enabled' => true]);

    $replacement = $this->postJson('/api/v1/settings/mcp/tokens/read')->assertOk()->json('token');

    expect($replacement)->not->toBe($read);
    settingsMcpProbe($read)->assertUnauthorized();
    settingsMcpProbe($replacement)->assertOk()->assertJsonCount(3, 'result.tools');
    settingsMcpProbe($write)->assertOk()->assertJsonCount(5, 'result.tools');
});

it('revokes one key without disabling MCP or removing the other key', function (): void {
    McpSetting::factory()->create([
        'enabled' => true, 'read_token_hash' => hash('sha256', 'read-key'),
        'write_token_hash' => hash('sha256', 'write-key'), 'read_token_generated_at' => now(),
    ]);

    $this->withSession(personalSession())->deleteJson('/api/v1/settings/mcp/tokens/read')->assertOk()
        ->assertJsonPath('enabled', true)->assertJsonPath('keys.read.configured', false)
        ->assertJsonPath('keys.read.generated_at', null)->assertJsonPath('keys.write.configured', true);
    settingsMcpProbe('read-key')->assertUnauthorized();
    settingsMcpProbe('write-key')->assertOk();
});

it('adopts legacy environment keys on the first edit and does not revive revoked keys', function (): void {
    config([
        'nolife.mcp_enabled' => true,
        'nolife.mcp_read_token_hash' => hash('sha256', 'legacy-read'),
        'nolife.mcp_write_token_hash' => hash('sha256', 'legacy-write'),
    ]);
    $this->withSession(personalSession())->getJson('/api/v1/settings/mcp')->assertOk()
        ->assertJsonPath('enabled', true)->assertJsonPath('keys.read.configured', true);
    $this->assertDatabaseCount('mcp_settings', 0);

    $this->deleteJson('/api/v1/settings/mcp/tokens/read')->assertOk();

    settingsMcpProbe('legacy-read')->assertUnauthorized();
    settingsMcpProbe('legacy-write')->assertOk();
    $this->patchJson('/api/v1/settings/mcp', ['enabled' => false])->assertOk();
    settingsMcpProbe('legacy-write')->assertNotFound();
});

it('rejects invalid toggle values without changing settings', function (array $payload): void {
    $this->withSession(personalSession())->patchJson('/api/v1/settings/mcp', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('enabled');
    $this->assertDatabaseCount('mcp_settings', 0);
})->with([[[]], [['enabled' => 'maybe']], [['enabled' => []]]]);

it('refuses unknown key access levels', function (): void {
    $this->withSession(personalSession())->postJson('/api/v1/settings/mcp/tokens/admin')->assertNotFound();
    $this->deleteJson('/api/v1/settings/mcp/tokens/admin')->assertNotFound();
    $this->assertDatabaseCount('mcp_settings', 0);
});

it('does not accept client-supplied hashes when changing the enabled switch', function (): void {
    $this->withSession(personalSession())->patchJson('/api/v1/settings/mcp', [
        'enabled' => true, 'write_token_hash' => hash('sha256', 'injected'),
    ])->assertOk()->assertJsonPath('keys.write.configured', false);
    expect(McpSetting::query()->findOrFail(1)->write_token_hash)->toBeNull();
});

it('upgrades the existing library without changing game records or media', function (): void {
    Storage::fake('local');
    $game = Game::factory()->create(['rating' => 9, 'duration_minutes' => 0, 'total_played_minutes' => 120]);
    $image = GameImage::factory()->for($game)->create(['path' => 'media/upgrade-proof.webp']);
    Storage::disk('local')->put($image->path, 'unchanged media fixture');
    $before = $game->fresh()->toArray();
    Schema::drop('mcp_settings');
    $migration = require database_path('migrations/2026_10_29_212951_create_mcp_settings_table.php');

    $migration->up();

    $this->withSession(personalSession())->getJson('/api/v1/settings/mcp')->assertOk()->assertJsonPath('enabled', false);
    expect($game->fresh()->toArray())->toBe($before);
    expect(Storage::disk('local')->get($image->path))->toBe('unchanged media fixture');
    $this->assertModelExists($image);
});
