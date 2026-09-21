<?php

use App\Enums\UserRole;
use Illuminate\Http\UploadedFile;

it('shows a user their own profile details', function () {
    $user = actingAsRole(UserRole::BudgetOfficer);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.name', $user->name)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role', 'budget_officer')
        ->assertJsonPath('data.designation', $user->designation)
        ->assertJsonPath('data.avatar_url', null);
});

it('keeps the profile read-only: users cannot change their own details or photo', function () {
    $user = actingAsRole(UserRole::FinancialAnalyst);
    $name = $user->name;

    $this->postJson('/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')])->assertNotFound();
    $this->deleteJson('/api/v1/me/avatar')->assertNotFound();
    $this->putJson('/api/v1/me', ['name' => 'Hacked', 'designation' => 'Boss'])->assertStatus(405);
    $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Hacked'])->assertForbidden();

    expect($user->fresh()->name)->toBe($name);
});
