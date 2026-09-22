<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

it('lets an administrator create a user with a hashed password', function () {
    actingAsRole(UserRole::Admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'Bianca Cruz',
        'email' => 'bianca@tesda.gov.ph',
        'role' => UserRole::BudgetOfficer->value,
        'designation' => 'Budget Officer III',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ])->assertCreated()->assertJsonPath('data.role', 'budget_officer');

    $user = User::find($response->json('data.id'));
    expect(Hash::check('super-secret', $user->password))->toBeTrue();
});

it('creates a user with an uploaded avatar', function () {
    Storage::fake('public');
    actingAsRole(UserRole::Admin);

    $file = UploadedFile::fake()->image('portrait.jpg', 200, 200);

    $response = $this->post('/api/v1/users', [
        'name' => 'Avatar User',
        'email' => 'avatar.user@tesda.gov.ph',
        'role' => UserRole::FinancialAnalyst->value,
        'designation' => 'Analyst',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
        'avatar' => $file,
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($response->json('data.avatar_url'))->not->toBeNull();
    $user = User::find($response->json('data.id'));
    Storage::disk('public')->assertExists($user->avatar_path);
});

it('never writes password hashes to the audit trail', function () {
    actingAsRole(UserRole::Admin);

    $response = $this->postJson('/api/v1/users', [
        'name' => 'Audit Check',
        'email' => 'audit.check@tesda.gov.ph',
        'role' => UserRole::BudgetOfficer->value,
        'designation' => 'Budget Officer II',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ])->assertCreated();

    $this->putJson('/api/v1/users/'.$response->json('data.id'), [
        'name' => 'Audit Check',
        'email' => 'audit.check@tesda.gov.ph',
        'role' => UserRole::BudgetOfficer->value,
        'designation' => 'Budget Officer II',
        'password' => 'another-secret',
        'password_confirmation' => 'another-secret',
    ])->assertOk();

    $logs = $this->getJson('/api/v1/audit-logs?type=User')->assertOk()->json('data');

    expect($logs)->not->toBeEmpty()
        ->and(json_encode($logs))->not->toContain('$2y$')
        ->and(json_encode($logs))->not->toContain('password');
});

it('updates a user without touching the password when none is given', function () {
    actingAsRole(UserRole::Admin);
    $user = User::factory()->role(UserRole::DisbursingOfficer)->create();
    $originalHash = $user->password;

    $this->putJson("/api/v1/users/{$user->id}", [
        'name' => 'Renamed Person',
        'email' => $user->email,
        'role' => UserRole::DisbursingOfficer->value,
        'designation' => $user->designation ?? 'Staff',
    ])->assertOk()->assertJsonPath('data.name', 'Renamed Person');

    expect($user->fresh()->password)->toBe($originalHash);
});

it('toggles a user active flag', function () {
    actingAsRole(UserRole::Admin);
    $user = User::factory()->create(['is_active' => true]);

    $this->postJson("/api/v1/users/{$user->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('deletes a user with no activity records', function () {
    actingAsRole(UserRole::Admin);
    $user = User::factory()->role(UserRole::BudgetOfficer)->create();

    $this->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();

    expect(User::find($user->id))->toBeNull();
});

it('blocks deleting a user who has activity records, and deactivating stays available', function () {
    actingAsRole(UserRole::Admin);
    $user = User::factory()->role(UserRole::BudgetOfficer)->create();

    AuditLog::create([
        'user_id' => $user->id,
        'user_name' => $user->name,
        'action' => 'created',
        'auditable_type' => User::class,
        'auditable_id' => $user->id,
    ]);

    $this->deleteJson("/api/v1/users/{$user->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors('user');

    expect(User::find($user->id))->not->toBeNull();

    $this->postJson("/api/v1/users/{$user->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('excludes administrators from the users list', function () {
    $admin = actingAsRole(UserRole::Admin);
    User::factory()->role(UserRole::BudgetOfficer)->create([
        'email' => 'listed.budget@tesda.gov.ph',
    ]);

    $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonFragment(['email' => 'listed.budget@tesda.gov.ph'])
        ->assertJsonMissing(['email' => $admin->email]);
});

it('rejects creating or assigning the administrator role', function () {
    actingAsRole(UserRole::Admin);

    $this->postJson('/api/v1/users', [
        'name' => 'Extra Admin',
        'email' => 'extra.admin@tesda.gov.ph',
        'role' => UserRole::Admin->value,
        'designation' => 'Administrator',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    $user = User::factory()->role(UserRole::BudgetOfficer)->create();

    $this->putJson("/api/v1/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::Admin->value,
        'designation' => $user->designation ?? 'Staff',
    ])->assertStatus(422)->assertJsonValidationErrors('role');
});

it('blocks managing the system administrator through the users API', function () {
    $admin = actingAsRole(UserRole::Admin);

    $this->getJson("/api/v1/users/{$admin->id}")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->postJson("/api/v1/users/{$admin->id}/toggle-active")->assertStatus(422)->assertJsonValidationErrors('user');
    $this->putJson("/api/v1/users/{$admin->id}", [
        'name' => $admin->name,
        'email' => 'hacked@tesda.gov.ph',
        'role' => UserRole::FinancialAnalyst->value,
        'designation' => $admin->designation ?? 'Administrator',
    ])->assertStatus(422)->assertJsonValidationErrors('user');

    expect($admin->fresh()->email)->toBe($admin->email);
});

it('lets the signed-in user change their password without changing email', function () {
    $admin = actingAsRole(UserRole::Admin);
    $originalEmail = $admin->email;

    $this->postJson('/api/v1/me/password', [
        'current_password' => 'password',
        'password' => 'new-secure-pass',
        'password_confirmation' => 'new-secure-pass',
    ])->assertOk();

    expect(Hash::check('new-secure-pass', $admin->fresh()->password))->toBeTrue()
        ->and($admin->fresh()->email)->toBe($originalEmail);

    $this->postJson('/api/v1/me/password', [
        'current_password' => 'wrong-password',
        'password' => 'another-pass',
        'password_confirmation' => 'another-pass',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');
});

it('forbids non-admins from managing users', function () {
    actingAsRole(UserRole::BudgetOfficer);

    $this->getJson('/api/v1/users')->assertForbidden();
});

it('filters users by role and search', function () {
    actingAsRole(UserRole::Admin);

    User::factory()->role(UserRole::BudgetOfficer)->create([
        'name' => 'Budget Filter Target',
        'email' => 'budget.filter@tesda.gov.ph',
    ]);
    User::factory()->role(UserRole::DisbursingOfficer)->create([
        'name' => 'Disbursing Other',
        'email' => 'disbursing.other@tesda.gov.ph',
    ]);

    $this->getJson('/api/v1/users?role=budget_officer')
        ->assertOk()
        ->assertJsonFragment(['email' => 'budget.filter@tesda.gov.ph'])
        ->assertJsonMissing(['email' => 'disbursing.other@tesda.gov.ph']);

    $this->getJson('/api/v1/users?role=budget_officer&search=Filter')
        ->assertOk()
        ->assertJsonFragment(['email' => 'budget.filter@tesda.gov.ph']);

    $this->getJson('/api/v1/users?role=admin')
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    $this->getJson('/api/v1/users?role=not_a_role')
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});
