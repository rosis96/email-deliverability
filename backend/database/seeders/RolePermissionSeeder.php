<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'dashboard.view' => 'View dashboards',
            'batches.view' => 'View batches',
            'batches.manage' => 'Create/update batches',
            'activity.view' => 'View daily activity',
            'activity.write' => 'Record/import daily activity',
            'problems.view' => 'View problems',
            'problems.manage' => 'Create/update problems',
            'deliverability.view' => 'View deliverability health',
            'rotation.view' => 'View rotation',
            'rotation.manage' => 'Manage rotation plans',
            'placement.view' => 'View placement tests',
            'placement.write' => 'Record placement tests',
            'approvals.request' => 'Request approvals',
            'approvals.decide' => 'Approve/reject actions',
            'settings.view' => 'View settings',
            'settings.manage' => 'Change settings',
            'integrations.view' => 'View integrations',
            'audit.view' => 'View audit logs',
        ];

        $perms = [];
        foreach ($permissions as $name => $label) {
            $perms[$name] = Permission::updateOrCreate(['name' => $name], ['label' => $label]);
        }

        $roles = [
            'super_admin' => ['label' => 'Super Admin', 'perms' => array_keys($permissions)],
            'deliverability_manager' => ['label' => 'Deliverability Manager', 'perms' => array_keys($permissions)],
            'operator' => ['label' => 'Operator', 'perms' => [
                'dashboard.view', 'batches.view', 'batches.manage', 'activity.view', 'activity.write',
                'problems.view', 'problems.manage', 'deliverability.view', 'rotation.view', 'rotation.manage',
                'placement.view', 'placement.write', 'approvals.request', 'settings.view', 'integrations.view', 'audit.view',
            ]],
            'viewer' => ['label' => 'Viewer', 'perms' => [
                'dashboard.view', 'batches.view', 'activity.view', 'problems.view', 'deliverability.view',
                'rotation.view', 'placement.view', 'settings.view', 'integrations.view', 'audit.view',
            ]],
        ];

        foreach ($roles as $name => $cfg) {
            $role = Role::updateOrCreate(['name' => $name], ['label' => $cfg['label']]);
            $ids = collect($cfg['perms'])->map(fn ($p) => $perms[$p]->id)->all();
            $role->permissions()->sync($ids);
        }
    }
}
