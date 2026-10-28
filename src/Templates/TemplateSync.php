<?php

namespace Seisigma\AccessProfiles\Templates;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Contracts\TemplateDefinitions;
use Seisigma\AccessProfiles\Events\Snapshot;
use Seisigma\AccessProfiles\Events\TemplatesSynced;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

class TemplateSync
{
    public function __construct(private Catalogue $catalogue, private TenantVersion $versions) {}

    /** @return list<string> */
    public function sync(bool $dryRun = false): array
    {
        $provider = config('access-profiles.templates');

        if ($provider === null) {
            return ['No template definitions configured'];
        }

        if (! is_string($provider) || ! is_subclass_of($provider, TemplateDefinitions::class)) {
            throw new InvalidArgumentException('Templates must implement '.TemplateDefinitions::class);
        }

        $definitions = [];
        $ruleSets = [];

        foreach ($provider::templates() as $definition) {
            if (isset($definitions[$definition->key])) {
                throw new InvalidArgumentException("Duplicate template key: {$definition->key}");
            }

            $definitions[$definition->key] = $definition;
            $ruleSets[$definition->key] = $this->rules($definition);
        }

        return DB::transaction(function () use ($definitions, $ruleSets, $dryRun): array {
            $profiles = Profile::withTrashed()->where('is_system', true)
                ->whereNull('tenant_type')->whereNull('tenant_id')->get()->keyBy('key');
            $changes = [];
            $changedIds = [];
            $before = [];
            $after = [];

            foreach ($definitions as $key => $definition) {
                $profile = $profiles->get($key);
                $creating = $profile === null;
                $previous = $profile === null ? null : Snapshot::profile($profile);
                $profile ??= new Profile(['key' => $key, 'is_system' => true, 'version' => 1]);
                $profile->fill([
                    'name' => $definition->name,
                    'description' => $definition->description,
                    'requires_condition' => $definition->requiresCondition,
                    'deleted_at' => null,
                ]);
                $storedRules = $profile->rules()->toBase()->pluck('effect', 'permission')->all();
                ksort($storedRules);

                if (! $creating && ! $profile->isDirty() && $storedRules === $ruleSets[$key]) {
                    continue;
                }

                $changes[] = ($creating ? 'Create: ' : 'Update: ').$key;

                if ($dryRun) {
                    continue;
                }

                if (! $creating) {
                    $profile->setAttribute('version', $profile->getAttribute('version') + 1);
                }

                $profile->save();
                $profile->rules()->delete();
                foreach ($ruleSets[$key] as $permission => $effect) {
                    $profile->rules()->create(['permission' => $permission, 'effect' => $effect]);
                }
                $changedIds[] = $profile->getKey();
                if ($previous !== null) {
                    $before[] = $previous;
                }
                $after[] = Snapshot::profile($profile->refresh());
            }

            foreach ($profiles as $key => $profile) {
                if (isset($definitions[$key]) || $profile->trashed()) {
                    continue;
                }

                $changes[] = 'Delete: '.$key;

                if (! $dryRun) {
                    $before[] = Snapshot::profile($profile);
                    $profile->setAttribute('version', $profile->getAttribute('version') + 1);
                    $profile->save();
                    $profile->delete();
                    $changedIds[] = $profile->getKey();
                    $after[] = Snapshot::profile($profile);
                }
            }

            if ($changedIds !== []) {
                $tenants = [];
                $assignments = Assignment::query()->whereIn('profile_id', $changedIds)->with('tenant')->get();
                foreach ($assignments as $assignment) {
                    $tenant = $assignment->getRelation('tenant');
                    if ($tenant instanceof Model) {
                        $tenants[$tenant->getMorphClass()][$tenant->getKey()] = $tenant;
                    }
                }

                DB::afterCommit(function () use ($tenants): void {
                    foreach ($tenants as $group) {
                        foreach ($group as $tenant) {
                            $this->versions->bump($tenant);
                        }
                    }
                });
                event(new TemplatesSynced(null, true, 'access-profiles:sync-templates', $before, $after));
            }

            return $changes === [] ? ['No changes'] : $changes;
        });
    }

    /** @return array<string, string> */
    private function rules(TemplateDefinition $definition): array
    {
        $rules = [];
        foreach (['allow' => $definition->allow, 'deny' => $definition->deny] as $effect => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($this->catalogue->expand($pattern) as $permission) {
                    $rules[$permission] = $effect;
                }
            }
        }
        ksort($rules);

        return $rules;
    }
}
