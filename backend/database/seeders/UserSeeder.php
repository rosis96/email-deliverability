<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'name');

        // Dev credentials only — documented in the README. Change in production.
        $users = [
            ['name' => 'Ops Admin', 'email' => 'admin@revcadence.local', 'role' => 'super_admin'],
            ['name' => 'Deliverability Manager', 'email' => 'manager@revcadence.local', 'role' => 'deliverability_manager'],
            ['name' => 'Operator', 'email' => 'operator@revcadence.local', 'role' => 'operator'],
            ['name' => 'Viewer', 'email' => 'viewer@revcadence.local', 'role' => 'viewer'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $roles[$u['role']] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }
}
