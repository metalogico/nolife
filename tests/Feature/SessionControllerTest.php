<?php

use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config(['nolife.username' => 'michele', 'nolife.password_hash' => Hash::make('correct-password-123')]);
});

it('exposes session state without disclosing credentials', function (): void {
    $this->getJson('/api/v1/session')->assertOk()
        ->assertJsonPath('authenticated', false)
        ->assertJsonPath('configured', true)
        ->assertJsonPath('username', null)
        ->assertJsonStructure(['csrf_token']);
});

it('authenticates the personal user and invalidates the session on logout', function (): void {
    $this->postJson('/api/v1/session', ['username' => 'michele', 'password' => 'correct-password-123'])
        ->assertOk()->assertJsonPath('authenticated', true)->assertJsonPath('username', 'michele');
    $this->getJson('/api/v1/library')->assertOk();
    $this->deleteJson('/api/v1/session')->assertOk();
    $this->getJson('/api/v1/library')->assertUnauthorized();
});

it('returns 422 for incorrect credentials', function (): void {
    $this->postJson('/api/v1/session', ['username' => 'michele', 'password' => 'incorrect'])
        ->assertUnprocessable()->assertJsonPath('errors.username.0', 'The username or password is incorrect.');
});

it('returns 503 when personal access is not configured', function (): void {
    config(['nolife.password_hash' => '']);
    $this->postJson('/api/v1/session', ['username' => 'michele', 'password' => 'correct-password-123'])
        ->assertServiceUnavailable();
});

it('returns 429 after five incorrect login attempts', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/session', ['username' => 'michele', 'password' => 'incorrect'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/session', ['username' => 'michele', 'password' => 'incorrect'])->assertTooManyRequests();
});

it('invalidates existing access when the configured password changes', function (): void {
    $this->withSession(personalSession());
    config(['nolife.password_hash' => Hash::make('changed-password-123')]);
    $this->getJson('/api/v1/games')->assertUnauthorized();
});
