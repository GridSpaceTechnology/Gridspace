<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            TaxonomySeeder::class,
            GridSpacePersonalityAssessmentSeeder::class,
            VerificationTypeSeeder::class,

            // Demo matching data - self-guards to local environments only.
            MatchingDemoSeeder::class,
        ]);
    }
}
