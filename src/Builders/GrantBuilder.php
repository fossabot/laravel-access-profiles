<?php

namespace Seisigma\AccessProfiles\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Seisigma\AccessProfiles\Exceptions\GrantRejected;
use Seisigma\AccessProfiles\Guards\HostRules;
use Seisigma\AccessProfiles\Guards\TenantConsistency;
use Seisigma\AccessProfiles\Models\Assignment;
use Seisigma\AccessProfiles\Models\Profile;
use Seisigma\AccessProfiles\Support\TenantVersion;

/**
 * `Access::grant('viewer')->to($user)->in($tenant)->onlyScopes([...])->by($admin)->save()`
 */
class GrantBuilder extends AssignmentBuilder
{
    private ?Model $holder = null;

    private ?Model $tenant = null;

    /** @var list<Model>|null null = the whole tenant */
    private ?array $scopes = null;

    private ?string $title = null;

    private ?CarbonInterface $from = null;

    private ?CarbonInterface $until = null;

    public function __construct(private readonly string|Profile $profile) {}

    public function to(Model $holder): static
    {
        $this->holder = $holder;

        return $this;
    }

    public function in(Model $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    /** @param list<Model> $scopes */
    public function onlyScopes(array $scopes): static
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function titled(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function validFrom(?CarbonInterface $from): static
    {
        $this->from = $from;

        return $this;
    }

    public function validUntil(?CarbonInterface $until): static
    {
        $this->until = $until;

        return $this;
    }

    public function save(): Assignment
    {
        $holder = $this->holder ?? throw new GrantRejected(GrantRejected::MISSING_HOLDER);
        $tenant = $this->tenant ?? throw new GrantRejected(GrantRejected::MISSING_TENANT);
        $consistency = app(TenantConsistency::class);
        $consistency->tenant($tenant);
        $profile = $this->resolveProfile($tenant);
        $consistency->profile($profile, $tenant);
        self::checkWindow($this->from, $this->until);

        if ($this->scopes === []) {
            throw new GrantRejected(GrantRejected::NO_SCOPES);
        }

        $scopes = $this->scopes ?? [];
        $consistency->scopes($scopes, $tenant);
        $this->guardActor($holder, $profile, $tenant, $scopes, escalation: true);
        app(HostRules::class)->check($holder, $profile, $tenant, $scopes);

        return DB::transaction(function () use ($consistency, $holder, $tenant, $profile, $scopes): Assignment {
            $consistency->noDuplicate($holder, $profile, $tenant, now());

            $assignment = Assignment::create([
                'tenant_type' => $tenant->getMorphClass(),
                'tenant_id' => $tenant->getKey(),
                'holder_type' => $holder->getMorphClass(),
                'holder_id' => $holder->getKey(),
                'profile_id' => $profile->getKey(),
                'profile_version' => $profile->getAttribute('version'),
                'covers_tenant' => $scopes === [],
                'title' => $this->title,
                'valid_from' => $this->from,
                'valid_until' => $this->until,
                'granted_by_type' => $this->grantor?->getMorphClass(),
                'granted_by_id' => $this->grantor?->getKey(),
                'reason' => $this->reason,
            ]);
            self::writeScopes($assignment, $scopes);
            DB::afterCommit(fn () => app(TenantVersion::class)->bump($tenant));

            return $assignment;
        });
    }

    /**
     * A key finds this tenant's copy first, then the template; never the copy
     * of another tenant. A given model is read again, so its stored version counts.
     */
    private function resolveProfile(Model $tenant): Profile
    {
        if ($this->profile instanceof Profile) {
            return Profile::query()->whereKey($this->profile->getKey())->first()
                ?? throw new GrantRejected(GrantRejected::UNKNOWN_PROFILE);
        }

        return Profile::query()
            ->where('key', $this->profile)
            ->where(fn (Builder $query) => $query->whereNull('tenant_type')
                ->orWhere(fn (Builder $query) => $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey())))
            ->orderByRaw('tenant_type is null')
            ->first()
            ?? throw new GrantRejected(GrantRejected::UNKNOWN_PROFILE);
    }
}
