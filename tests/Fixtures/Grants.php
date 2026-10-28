<?php

namespace Seisigma\AccessProfiles\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\AssignmentScope;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Models\ProfileRule;

/**
 * Inserts profiles and assignments straight into the tables, so the resolver
 * tests do not depend on the grant builders.
 */
class Grants
{
    /**
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    public static function profile(string $key, array $allow = [], array $deny = [], ?Model $tenant = null, ?string $condition = null): Profile
    {
        $profile = Profile::create([
            'key' => $key,
            'name' => ucfirst($key),
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'is_system' => $tenant === null,
            'requires_condition' => $condition,
        ]);

        foreach ($allow as $permission) {
            ProfileRule::create(['profile_id' => $profile->id, 'permission' => $permission, 'effect' => 'allow']);
        }

        foreach ($deny as $permission) {
            ProfileRule::create(['profile_id' => $profile->id, 'permission' => $permission, 'effect' => 'deny']);
        }

        return $profile;
    }

    /**
     * @param  list<Model>|null  $scopes  null = covers the whole tenant
     * @param  array<string, mixed>  $attributes
     */
    public static function assign(Model $holder, Profile $profile, Model $tenant, ?array $scopes = null, array $attributes = []): Assignment
    {
        $assignment = Assignment::create(array_merge([
            'tenant_type' => $tenant->getMorphClass(),
            'tenant_id' => $tenant->getKey(),
            'holder_type' => $holder->getMorphClass(),
            'holder_id' => $holder->getKey(),
            'profile_id' => $profile->id,
            'profile_version' => $profile->version ?? 1,
            'covers_tenant' => $scopes === null,
        ], $attributes));

        foreach ($scopes ?? [] as $scope) {
            AssignmentScope::create([
                'assignment_id' => $assignment->id,
                'scope_type' => $scope->getMorphClass(),
                'scope_id' => $scope->getKey(),
            ]);
        }

        return $assignment;
    }
}
