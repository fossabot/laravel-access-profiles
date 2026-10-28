<?php

namespace Seisigma\AccessProfiles\Queries;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Seisigma\AccessProfiles\Resolver\GrantSet;

/**
 * Where one holder has one permission inside one tenant. Three shapes:
 * the whole tenant; the whole tenant except some denied scopes; or a list
 * of scopes (maybe empty). Built from the active, condition-on entries of a
 * grant set with the decision rules of DESIGN §2.1 (deny wins).
 *
 * @phpstan-import-type Entry from GrantSet
 */
final class ScopeSet
{
    /**
     * @param  list<string>  $scopes  allowed scope keys ("type|id") when not tenant-wide
     * @param  list<string>  $except  denied scope keys when tenant-wide
     */
    private function __construct(
        private readonly bool $tenantWide,
        private readonly array $scopes,
        private readonly array $except,
    ) {}

    public static function none(): self
    {
        return new self(false, [], []);
    }

    /** @param list<Entry> $entries active entries of one tenant whose condition is on */
    public static function fromEntries(array $entries, string $permission): self
    {
        $tenantWide = false;
        $allowed = [];
        $denied = [];

        foreach ($entries as $entry) {
            // Deny is read first: an entry that lists the key as deny never allows it.
            $deny = in_array($permission, $entry['deny'], true);

            if (! $deny && ! in_array($permission, $entry['allow'], true)) {
                continue;
            }

            // A covers-tenant deny covers every scope: nothing is left.
            if ($entry['covers_tenant'] && $deny) {
                return self::none();
            }

            if ($entry['covers_tenant']) {
                $tenantWide = true;
            } elseif ($deny) {
                $denied = [...$denied, ...$entry['scopes']];
            } else {
                $allowed = [...$allowed, ...$entry['scopes']];
            }
        }

        if ($tenantWide) {
            return new self(true, [], array_values(array_unique($denied)));
        }

        return new self(false, array_values(array_diff(array_unique($allowed), $denied)), []);
    }

    /** True when the holder has the permission in every scope of the tenant. */
    public function coversTenant(): bool
    {
        return $this->tenantWide && $this->except === [];
    }

    /**
     * The listed scopes of this class where the holder has the permission.
     * Empty for a tenant-wide set: check coversTenant() and exceptIds(), or use constrain().
     *
     * @param  class-string<Model>  $class
     * @return list<int|string>
     */
    public function ids(string $class): array
    {
        return self::idsOf($this->scopes, self::typeOf($class));
    }

    /**
     * The scopes of this class denied inside a tenant-wide set.
     *
     * @param  class-string<Model>  $class
     * @return list<int|string>
     */
    public function exceptIds(string $class): array
    {
        return self::idsOf($this->except, self::typeOf($class));
    }

    /**
     * Limits a query on scope rows to this set. Adds nothing when the set
     * covers the tenant (the query must already be limited to the tenant),
     * and returns no rows at all when the set is empty.
     *
     * @template TQuery of EloquentBuilder<covariant Model>|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  class-string<Model>|null  $class  the scope class; null = the only class in the set
     * @return TQuery
     */
    public function constrain(EloquentBuilder|QueryBuilder $query, string $column, ?string $class = null): EloquentBuilder|QueryBuilder
    {
        if ($this->coversTenant()) {
            return $query;
        }

        $type = $class === null ? $this->onlyType() : self::typeOf($class);
        $ids = self::idsOf($this->tenantWide ? $this->except : $this->scopes, $type);

        // The builders change in place; returning $query keeps the caller's type.
        match (true) {
            $this->tenantWide => $query->whereNotIn($column, $ids),
            $ids === [] => $query->whereRaw('0 = 1'),
            default => $query->whereIn($column, $ids),
        };

        return $query;
    }

    /**
     * The morph type stored for a scope class. Grant set keys are strings,
     * so a numeric morph-map alias is compared as a string.
     *
     * @param  class-string<Model>  $class
     */
    private static function typeOf(string $class): string
    {
        return (string) Relation::getMorphAlias($class);
    }

    private function onlyType(): ?string
    {
        $types = array_unique(array_map(
            fn (string $key): string => substr($key, 0, (int) strrpos($key, '|')),
            [...$this->scopes, ...$this->except],
        ));

        if (count($types) > 1) {
            throw new InvalidArgumentException('This scope set holds more than one scope type; pass the scope class to constrain().');
        }

        return $types === [] ? null : reset($types);
    }

    /**
     * @param  list<string>  $keys
     * @return list<int|string>
     */
    private static function idsOf(array $keys, ?string $type): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $split = (int) strrpos($key, '|');

            if (substr($key, 0, $split) === $type) {
                $id = substr($key, $split + 1);
                $ids[] = ctype_digit($id) ? (int) $id : $id;
            }
        }

        sort($ids);

        return $ids;
    }
}
