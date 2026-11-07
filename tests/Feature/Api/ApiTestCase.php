<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AreaSeeder;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesAndPermissionsSeeder::class, CatalogueSeeder::class, AreaSeeder::class]);
    }

    /**
     * Requests as the WorkMate website makes them, so Sanctum gives them a
     * session (it does so only for its stateful domains).
     */
    protected function fromWebsite(): self
    {
        return $this->withHeaders(['Referer' => 'http://localhost/', 'Accept' => 'application/json']);
    }

    /** A user holding the given roles (none = an ordinary customer). */
    protected function userWithRoles(array $roles = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::whereIn('key', $roles)->pluck('id'));

        return $user;
    }

    /**
     * Nobody is listed before their ID is checked: marks every applicant's
     * documents as approved, for tests about the details-only approval.
     */
    protected function idChecked(): self
    {
        \App\Models\ProviderProfile::whereNull('document_verified_at')->update(['document_verified_at' => now()]);

        return $this;
    }

    protected function signIn(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }
}
