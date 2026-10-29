<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminNacionalRoleSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $admin = Role::findByName('Admin', 'web');
            $national = Role::findOrCreate('Admin Nacional', 'web');
            $national->syncPermissions($admin->permissions);

            // Ocultar la opción anterior conservando las afiliaciones existentes.
            DB::table('institutions')->where('name', 'Admin Nacional')
                ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
