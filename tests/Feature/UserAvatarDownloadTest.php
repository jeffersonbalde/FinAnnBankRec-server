<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('lets any signed-in user download a profile photo under a readable name', function () {
    $owner = User::factory()->create([
        'name' => 'Joe Ann D. Nisnisan',
        'avatar_path' => UploadedFile::fake()->image('photo.jpg')->store('avatars', 'public'),
    ]);

    foreach ([UserRole::Admin, UserRole::FinancialAnalyst, UserRole::DisbursingOfficer] as $role) {
        actingAsRole($role);

        $this->get("/api/v1/users/{$owner->id}/avatar")
            ->assertOk()
            ->assertDownload('joe-ann-d-nisnisan-photo.jpg');
    }
});

it('answers 404 when the user has no photo, and refuses anyone who is not signed in', function () {
    $plain = User::factory()->create(['avatar_path' => null]);

    $this->getJson("/api/v1/users/{$plain->id}/avatar")->assertUnauthorized();

    actingAsRole(UserRole::Admin);
    $this->getJson("/api/v1/users/{$plain->id}/avatar")->assertNotFound();
});
