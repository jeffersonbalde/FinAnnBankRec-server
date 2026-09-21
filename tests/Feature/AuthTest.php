<?php

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Requests from the SPA carry an Origin that Sanctum recognises as a stateful
 * domain, which is what starts the session the cookie-auth flow relies on.
 */
function fromSpa(): TestCase
{
    return test()->withHeader('Origin', config('app.url'));
}

it('logs in with valid credentials and returns the user with role', function () {
    $user = User::factory()->role(UserRole::FinancialAnalyst)->create([
        'email' => 'analyst@example.test',
        'password' => 'secret-pass',
    ]);

    $response = fromSpa()->postJson('/api/v1/login', [
        'email' => 'analyst@example.test',
        'password' => 'secret-pass',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', 'analyst@example.test')
        ->assertJsonPath('data.role', 'financial_analyst')
        ->assertJsonPath('data.role_label', 'Financial Analyst');

    $this->assertAuthenticatedAs($user);
});

it('rejects an invalid password', function () {
    User::factory()->create([
        'email' => 'someone@example.test',
        'password' => 'right-pass',
    ]);

    fromSpa()->postJson('/api/v1/login', [
        'email' => 'someone@example.test',
        'password' => 'wrong-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('blocks a deactivated account from logging in', function () {
    User::factory()->inactive()->create([
        'email' => 'inactive@example.test',
        'password' => 'secret-pass',
    ]);

    fromSpa()->postJson('/api/v1/login', [
        'email' => 'inactive@example.test',
        'password' => 'secret-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertGuest();
});

it('requires authentication for the me endpoint', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the authenticated user from the me endpoint', function () {
    $user = User::factory()->role(UserRole::Admin)->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'admin');
});

it('logs the user out', function () {
    $user = User::factory()->create();

    fromSpa()->actingAs($user)
        ->postJson('/api/v1/logout')
        ->assertOk();
});
