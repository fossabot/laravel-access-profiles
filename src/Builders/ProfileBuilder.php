<?php

namespace Seisigma\AccessProfiles\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Catalogue\Catalogue;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

/**
 * Edits the profiles of one tenant. System templates are read-only here:
 * `access-profiles:sync-templates` owns them.
 *
 * `Access::profile('clerk')->in($tenant)->allow('billing.*')->deny('billing.invoice.approve')->save()`
 * merges into the stored rules: allow and deny set a key, forget removes it,
 * and a deny wins over an allow given to the same builder.
 */
class ProfileBuilder
{
    private ?Model $tenant = null;

    private ?string $name = null;

    private ?string $description = null;

    /** @var list<string> */
    private array $allow = [];

    /** @var list<string> */
    private array $deny = [];

    /** @var list<string> */
    private array $forget = [];

    public function __construct(private readonly string $key) {}

    public function in(Model $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function named(string $name, ?string $description = null): static
    {
        $this->name = $name;
        $this->description = $description;

        return $this;
    }

    public function allow(string $pattern): static
    {
        $this->allow[] = $pattern;

        return $this;
    }

    public function deny(string $pattern): static
    {
        $this->deny[] = $pattern;

        return $this;
    }

    public function forget(string $pattern): static
    {
        $this->forget[] = $pattern;

        return $this;
    }

    public function save(): Profile
    {
        $tenant = $this->tenant();
        // Expand before the transaction: an unknown key throws and nothing is written.
        $changes = $this->changes();

        return DB::transaction(function () use ($tenant, $changes): Profile {
            $profile = $this->stored($tenant)->lockForUpdate()->first();

            if ($profile?->getAttribute('is_system')) {
                throw new GrantRejected(GrantRejected::SYSTEM_TEMPLATE);
            }

            $profile ??= new Profile([
                'key' => $this->key,
                'name' => $this->key,
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenant->getKey(),
            ]);

            if ($this->name !== null) {
                $profile->fill(['name' => $this->name, 'description' => $this->description]);
            }

            $stored = $profile->exists ? $profile->rules()->toBase()->pluck('effect', 'permission')->all() : [];
            // The merge keeps the stored key order, so equal rules compare equal.
            $rules = array_diff_key(array_merge($stored, $changes['set']), array_flip($changes['forget']));
            $rulesChanged = $rules !== $stored;

            if ($rulesChanged && $profile->exists) {
                $profile->setAttribute('version', $profile->getAttribute('version') + 1);
            }

            $profile->save();

            if ($rulesChanged) {
                $profile->rules()->delete();
                foreach ($rules as $permission => $effect) {
                    $profile->rules()->create(['permission' => $permission, 'effect' => $effect]);
                }
            }

            DB::afterCommit(fn () => app(TenantVersion::class)->bump($tenant));

            return $profile->refresh();
        });
    }

    /**
     * Creates this tenant's own copy of the template with the same key.
     */
    public function copyFromTemplate(): Profile
    {
        $tenant = $this->tenant();

        return DB::transaction(function () use ($tenant): Profile {
            $template = Profile::query()->whereNull('tenant_type')->whereNull('tenant_id')->where('key', $this->key)->first()
                ?? throw new GrantRejected(GrantRejected::UNKNOWN_PROFILE);

            // A soft-deleted copy still holds the key in the unique index.
            if ($this->stored($tenant)->withTrashed()->lockForUpdate()->exists()) {
                throw new GrantRejected(GrantRejected::DUPLICATE_PROFILE);
            }

            $copy = Profile::create([
                'key' => $this->key,
                'name' => $template->getAttribute('name'),
                'description' => $template->getAttribute('description'),
                'requires_condition' => $template->getAttribute('requires_condition'),
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenant->getKey(),
                'based_on_id' => $template->getKey(),
            ]);

            foreach ($template->rules()->toBase()->pluck('effect', 'permission') as $permission => $effect) {
                $copy->rules()->create(['permission' => $permission, 'effect' => $effect]);
            }

            DB::afterCommit(fn () => app(TenantVersion::class)->bump($tenant));

            return $copy->refresh();
        });
    }

    private function tenant(): Model
    {
        $tenant = $this->tenant ?? throw new GrantRejected(GrantRejected::SYSTEM_TEMPLATE);
        app(TenantConsistency::class)->tenant($tenant);

        return $tenant;
    }

    /** @return Builder<Profile> */
    private function stored(Model $tenant): Builder
    {
        return Profile::query()
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey())
            ->where('key', $this->key);
    }

    /** @return array{set: array<string, string>, forget: list<string>} */
    private function changes(): array
    {
        $catalogue = app(Catalogue::class);
        $set = [];
        $forget = [];

        foreach ($this->allow as $pattern) {
            foreach ($catalogue->expand($pattern) as $permission) {
                $set[$permission] = 'allow';
            }
        }

        foreach ($this->deny as $pattern) {
            foreach ($catalogue->expand($pattern) as $permission) {
                $set[$permission] = 'deny';
            }
        }

        foreach ($this->forget as $pattern) {
            array_push($forget, ...$catalogue->expand($pattern));
        }

        return ['set' => $set, 'forget' => $forget];
    }
}
