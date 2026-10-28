<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('merges the default table names into config', function () {
    expect(config('access-profiles.tables'))->toBe([
        'profiles' => 'access_profiles',
        'profile_permissions' => 'access_profile_permissions',
        'assignments' => 'access_assignments',
        'assignment_scopes' => 'access_assignment_scopes',
    ]);
});

it('creates the four tables and drops them again', function () {
    $this->runPackageMigration();

    foreach (config('access-profiles.tables') as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    (include __DIR__.'/../database/migrations/create_access_profiles_tables.php.stub')->down();

    foreach (config('access-profiles.tables') as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('uses the configured table names', function () {
    config()->set('access-profiles.tables.assignments', 'custom_grants');

    $this->runPackageMigration();

    expect(Schema::hasTable('custom_grants'))->toBeTrue()
        ->and(Schema::hasTable('access_assignments'))->toBeFalse();
});

it('stores an assignment that covers a set of scopes', function () {
    $this->runPackageMigration();

    $profileId = DB::table('access_profiles')->insertGetId([
        'key' => 'reviewer', 'name' => 'Reviewer', 'is_system' => true,
    ]);
    $assignmentId = DB::table('access_assignments')->insertGetId([
        'tenant_type' => 'team', 'tenant_id' => 1,
        'holder_type' => 'user', 'holder_id' => 7,
        'profile_id' => $profileId, 'profile_version' => 1,
        'covers_tenant' => false, 'title' => 'Board treasurer',
    ]);
    DB::table('access_assignment_scopes')->insert([
        ['assignment_id' => $assignmentId, 'scope_type' => 'site', 'scope_id' => 3],
        ['assignment_id' => $assignmentId, 'scope_type' => 'site', 'scope_id' => 4],
    ]);

    expect(DB::table('access_assignment_scopes')->where('assignment_id', $assignmentId)->pluck('scope_id')->all())
        ->toEqualCanonicalizing([3, 4]);
});
