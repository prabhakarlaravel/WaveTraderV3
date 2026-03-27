<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@wavetrader.dev'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
            ]
        );
    }
}
