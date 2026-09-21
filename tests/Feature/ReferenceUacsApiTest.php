<?php

use App\Enums\UserRole;
use App\Models\ReferenceUacs;

it('lets an administrator manage UACS reference codes', function () {
    actingAsRole(UserRole::Admin);

    $created = $this->postJson('/api/v1/reference-uacs', [
        'code' => '5020202000',
        'description' => 'Scholarship Grants/Expenses',
    ])->assertCreated()->assertJsonPath('data.code', '5020202000');

    $id = $created->json('data.id');

    $this->putJson("/api/v1/reference-uacs/{$id}", [
        'code' => '5020202000',
        'description' => 'Training Expenses',
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/v1/reference-uacs/{$id}")->assertNoContent();
});

it('rejects a duplicate UACS code', function () {
    actingAsRole(UserRole::Admin);
    ReferenceUacs::factory()->create(['code' => '5020202000']);

    $this->postJson('/api/v1/reference-uacs', [
        'code' => '5020202000',
        'description' => 'Something',
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('forbids non-admins', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $this->getJson('/api/v1/reference-uacs')->assertForbidden();
});
