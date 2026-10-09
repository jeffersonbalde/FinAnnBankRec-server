<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Config as it would be built with the given environment values. */
function filesystemsConfigWith(array $env): array
{
    foreach ($env as $key => $value) {
        Env::getRepository()->set($key, $value);
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        foreach (array_keys($env) as $key) {
            Env::getRepository()->clear($key);
        }
    }
}

it('keeps profile photos on the local disk unless a Spaces bucket is configured', function () {
    $disk = filesystemsConfigWith([])['disks']['public'];

    expect($disk['driver'])->toBe('local')
        ->and($disk['root'])->toBe(storage_path('app/public'));
});

it('keeps profile photos in the Spaces bucket, under the app\'s own folder, when it is configured', function () {
    $disk = filesystemsConfigWith([
        'SPACES_BUCKET' => 'finann-files',
        'SPACES_KEY' => 'key',
        'SPACES_SECRET' => 'secret',
        'SPACES_URL' => 'https://finann-files.sgp1.digitaloceanspaces.com/',
    ])['disks']['public'];

    expect($disk)->toMatchArray([
        'driver' => 's3',
        'bucket' => 'finann-files',
        'region' => 'sgp1',
        'endpoint' => 'https://sgp1.digitaloceanspaces.com',
        'root' => 'finann',
        'visibility' => 'public',
    ]);

    // The address a browser would use to show the photo.
    config(['filesystems.disks.spaces_check' => $disk]);
    expect(Storage::disk('spaces_check')->url('avatars/ana.jpg'))
        ->toBe('https://finann-files.sgp1.digitaloceanspaces.com/finann/avatars/ana.jpg');
});

it('brings back the demo photos whose file is gone, without touching passwords', function () {
    Storage::fake('public');
    Http::fake(['randomuser.me/*' => Http::response(str_repeat('x', 2000), 200)]);

    $present = User::factory()->create(['email' => 'analyst@tesda.gov.ph', 'avatar_path' => 'avatars/present.jpg']);
    Storage::disk('public')->put('avatars/present.jpg', 'keep');
    $missing = User::factory()->create(['email' => 'admin@tesda.gov.ph', 'avatar_path' => 'avatars/gone.jpg']);
    $password = $missing->password;

    $this->artisan('finann:restore-demo-avatars')->assertSuccessful();

    expect(Storage::disk('public')->exists('avatars/gone.jpg'))->toBeTrue()
        ->and(Storage::disk('public')->get('avatars/present.jpg'))->toBe('keep')
        ->and($missing->fresh()->password)->toBe($password);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/portraits/men/32.jpg'));
    expect($present->fresh()->avatar_path)->toBe('avatars/present.jpg');
});

it('fails the restore command when a photo cannot be downloaded', function () {
    Storage::fake('public');
    Http::fake(['randomuser.me/*' => Http::response('nope', 404)]);
    User::factory()->create(['email' => 'admin@tesda.gov.ph', 'avatar_path' => 'avatars/gone.jpg']);

    $this->artisan('finann:restore-demo-avatars')->assertFailed();
});

it('answers a photo download with a clear message when the file is missing from the storage', function () {
    Storage::fake('public');
    actingAsRole(UserRole::Admin);
    $user = User::factory()->create(['avatar_path' => 'avatars/gone.jpg']);

    $this->getJson("/api/v1/users/{$user->id}/avatar")
        ->assertNotFound()
        ->assertJsonPath('message', 'The photo file is no longer on the server. Please upload the photo again.');
});
