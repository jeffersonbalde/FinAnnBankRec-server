<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /**
     * Seed demo role accounts plus extra staff with professional portraits.
     * All demo accounts use the password "password".
     *
     * Portraits come from randomuser.me (clean stock headshots), not pravatar,
     * so demos stay client-appropriate.
     */
    public function run(): void
    {
        Storage::disk('public')->makeDirectory('avatars');

        // gender + portrait index map to stable professional headshots
        $accounts = [
            [
                'name' => 'System Administrator',
                'email' => 'admin@tesda.gov.ph',
                'role' => UserRole::Admin,
                'designation' => 'Information Systems Analyst',
                'portrait' => ['men', 32],
            ],
            [
                'name' => 'Joe Ann D. Nisnisan',
                'email' => 'analyst@tesda.gov.ph',
                'role' => UserRole::FinancialAnalyst,
                'designation' => 'Administrative Officer IV / Financial Analyst',
                'portrait' => ['women', 44],
            ],
            [
                'name' => 'Fernando M. Manlaran',
                'email' => 'disbursing@tesda.gov.ph',
                'role' => UserRole::DisbursingOfficer,
                'designation' => 'Administrative Assistant III / Disbursing Officer',
                'portrait' => ['men', 11],
            ],
            [
                'name' => 'Budget Officer',
                'email' => 'budget@tesda.gov.ph',
                'role' => UserRole::BudgetOfficer,
                'designation' => 'Budget Officer III',
                'portrait' => ['women', 68],
            ],
            [
                'name' => 'Maria L. Santos',
                'email' => 'msantos@tesda.gov.ph',
                'role' => UserRole::FinancialAnalyst,
                'designation' => 'Administrative Officer III',
                'portrait' => ['women', 21],
            ],
            [
                'name' => 'Juan P. Reyes',
                'email' => 'jreyes@tesda.gov.ph',
                'role' => UserRole::BudgetOfficer,
                'designation' => 'Budget Officer II',
                'portrait' => ['men', 75],
            ],
            [
                'name' => 'Ana C. Dela Cruz',
                'email' => 'adelacruz@tesda.gov.ph',
                'role' => UserRole::DisbursingOfficer,
                'designation' => 'Administrative Aide VI',
                'portrait' => ['women', 33],
            ],
            [
                'name' => 'Roberto G. Lim',
                'email' => 'rlim@tesda.gov.ph',
                'role' => UserRole::FinancialAnalyst,
                'designation' => 'Senior Financial Analyst',
                'portrait' => ['men', 52],
            ],
            [
                'name' => 'Catherine M. Go',
                'email' => 'cgo@tesda.gov.ph',
                'role' => UserRole::FinancialAnalyst,
                'designation' => 'IT Officer I',
                'portrait' => ['women', 12],
            ],
            [
                'name' => 'Paolo S. Mendoza',
                'email' => 'pmendoza@tesda.gov.ph',
                'role' => UserRole::BudgetOfficer,
                'designation' => 'Budget Assistant',
                'portrait' => ['men', 36],
            ],
            [
                'name' => 'Grace A. Villanueva',
                'email' => 'gvillanueva@tesda.gov.ph',
                'role' => UserRole::DisbursingOfficer,
                'designation' => 'Cashier II',
                'portrait' => ['women', 47],
            ],
            [
                'name' => 'Mark E. Torralba',
                'email' => 'mtorralba@tesda.gov.ph',
                'role' => UserRole::FinancialAnalyst,
                'designation' => 'Administrative Officer II',
                'portrait' => ['men', 41],
            ],
        ];

        foreach ($accounts as $account) {
            [$folder, $index] = $account['portrait'];
            $path = $this->downloadPortrait($account['name'], $folder, $index);

            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make('password'),
                    'role' => $account['role'],
                    'designation' => $account['designation'],
                    'avatar_path' => $path,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * A failed download (offline server, blocked host) must not abort a deploy:
     * the user simply gets no portrait and the UI falls back to initials.
     */
    private function downloadPortrait(string $name, string $folder, int $index): ?string
    {
        $filename = 'avatars/'.Str::slug($name).'-'.substr(md5("{$folder}-{$index}"), 0, 8).'.jpg';
        $url = "https://randomuser.me/api/portraits/{$folder}/{$index}.jpg";

        try {
            $response = Http::timeout(20)->get($url);
        } catch (\Throwable) {
            $this->command?->warn("No portrait for {$name} (could not reach {$url}).");

            return null;
        }

        if ($response->successful() && strlen($response->body()) > 1000) {
            Storage::disk('public')->put($filename, $response->body());

            return $filename;
        }

        $this->command?->warn("No portrait for {$name} (bad response from {$url}).");

        return null;
    }
}
