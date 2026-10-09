<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class RestoreDemoAvatarsCommand extends Command
{
    protected $signature = 'finann:restore-demo-avatars';

    protected $description = 'Bring back the demo accounts\' profile photos whose file is missing from the storage (e.g. after a redeploy). Passwords and every other detail are left untouched.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $restored = 0;
        $failed = 0;

        foreach (DemoSeeder::accounts() as $account) {
            $user = User::query()->where('email', $account['email'])->first();

            // Only people who have a photo on record whose file is gone.
            if ($user === null || ! $user->avatar_path || $disk->exists($user->avatar_path)) {
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
                $disk->put($user->avatar_path, $response->body());
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
