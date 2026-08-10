<?php

use Database\Seeders\RefactorFixtureSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RefactorFixtureSeeder::class);
    }
}
