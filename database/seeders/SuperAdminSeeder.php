<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@limade.com'],
            [
                'name' => 'Super Admin Limade',
                'phone' => '081199998888',
                'password' => Hash::make('password'),
                'is_super_admin' => true,
            ]
        );
    }
}
