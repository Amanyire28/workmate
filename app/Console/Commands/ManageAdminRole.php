<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleAssignment;
use Illuminate\Console\Command;

/**
 * Grants or removes an admin role from the command line, for example to give
 * the first Super Administrator access. The change is written to the audit log.
 */
class ManageAdminRole extends Command
{
    protected $signature = 'workmate:role
                            {email : Email address of an existing account}
                            {role : Role key, e.g. super_admin}
                            {--revoke : Remove the role instead of granting it}';

    protected $description = 'Grant or remove an administrator role for an existing account';

    public function handle(RoleAssignment $roles): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        $role = Role::where('key', $this->argument('role'))->first();

        if (! $user) {
            $this->error('No account uses that email address.');

            return 1;
        }

        if (! $role) {
            $this->error('Unknown role. Available: '.Role::orderBy('key')->pluck('key')->implode(', ').'.');
            $this->line('If the list is empty, run: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return 1;
        }

        try {
            if ($this->option('revoke')) {
                $roles->revoke($user, $role, null, 'Removed from the command line');
                $this->info("Removed {$role->name} from {$user->email}.");
            } else {
                $roles->grant($user, $role, null, 'Granted from the command line');
                $this->info("Granted {$role->name} to {$user->email}.");
            }
        } catch (BusinessRuleException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
