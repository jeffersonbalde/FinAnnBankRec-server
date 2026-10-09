<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestoreDemoAvatarsCommand extends Command
{
    protected $signature = 'finann:restore-demo-avatars {--force : Download every demo photo again, even when the file is there (puts back the original demo photos)}';

    protected $description = 'Bring back the demo accounts\' profile photos whose file is missing from the storage (e.g. after a redeploy); with --force, reset all of them to the original demo photos. Passwords and every other detail are left untouched.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $force = (bool) $this->option('force');
        $restored = 0;
        $failed = 0;

        foreach (DemoSeeder::accounts() as $account) {
            $user = User::query()->where('email', $account['email'])->first();

            if ($user === null) {
                continue;
            }

            // Normally only people whose photo file is gone; --force does everyone.
            if (! $force && (! $user->avatar_path || $disk->exists($user->avatar_path))) {
                continue;
            }

            [$folder, $index] = $account['portrait'];
            $url = "https://randomuser.me/api/portraits/{$folder}/{$index}.jpg";

            try {
                $response = Http::timeout(20)->get($url);
            } catch (\Throwable) {
                $response = null;
            }

            if ($response?->successful() && strlen($response->body()) > 1000) {
                // Same file name the seeder uses, for someone who has no photo on record.
                $path = $user->avatar_path ?: 'avatars/'.Str::slug($account['name']).'-'.substr(md5("{$folder}-{$index}"), 0, 8).'.jpg';

                $disk->put($path, $response->body());

                if ($user->avatar_path !== $path) {
                    $user->forceFill(['avatar_path' => $path])->saveQuietly();
                }

                $restored++;
                $this->line("Restored {$user->name}");
            } else {
                $failed++;
                $this->warn("Could not download the photo of {$user->name}.");
            }
        }

        $this->info("Restored {$restored} photo(s)".($failed > 0 ? ", {$failed} could not be downloaded." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
