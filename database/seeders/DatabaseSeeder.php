<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Default data for a fresh deploy: users, bank accounts + signatories, UACS
     * codes, and past-period sample activity (reconciliations, checks,
     * notifications, audit trail) on other accounts. The July 2026 period on
     * LBP 1292-0001-01 is left free for a live walkthrough.
     *
     * Model events stay on so the audit trail is populated.
     */
    public function run(): void
    {
        $this->call([
            DemoSeeder::class,
            MasterDataSeeder::class,
            SampleActivitySeeder::class,
        ]);
    }
}
