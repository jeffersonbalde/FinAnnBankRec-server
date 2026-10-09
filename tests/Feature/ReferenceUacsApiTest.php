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

it('deletes several UACS codes at once and reports ones that are already gone', function () {
    actingAsRole(UserRole::Admin);
    $codes = ReferenceUacs::factory()->count(3)->create();
    $keep = ReferenceUacs::factory()->create();

    $this->postJson('/api/v1/reference-uacs/bulk-delete', ['ids' => [$codes[0]->id, $codes[1]->id, 999999]])
        ->assertOk()
        ->assertJsonPath('deleted', 2)
        ->assertJsonCount(1, 'skipped');

    $this->assertDatabaseMissing('reference_uacs', ['id' => $codes[0]->id]);
    $this->assertDatabaseHas('reference_uacs', ['id' => $codes[2]->id]);
    $this->assertDatabaseHas('reference_uacs', ['id' => $keep->id]);

    $this->postJson('/api/v1/reference-uacs/bulk-delete', ['ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');

    actingAsRole(UserRole::FinancialAnalyst);
    $this->postJson('/api/v1/reference-uacs/bulk-delete', ['ids' => [$keep->id]])->assertForbidden();
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
