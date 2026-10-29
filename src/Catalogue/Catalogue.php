<?php

namespace Seisigma\AccessProfiles\Catalogue;

use InvalidArgumentException;
use Seisigma\AccessProfiles\Contracts\PermissionCatalogue;

class Catalogue
{
    /** @var list<string> */
    private array $keys = [];

    /** @param list<class-string<PermissionCatalogue>> $catalogues */
    public function __construct(array $catalogues)
    {
        foreach ($catalogues as $catalogue) {
            foreach ($catalogue::keys() as $key) {
                if (! preg_match('/\A[a-z]+(?:_[a-z]+)*(?:\.[a-z]+(?:_[a-z]+)*){1,3}\z/', $key)) {
                    throw new InvalidArgumentException("Invalid permission key: {$key}");
                }

                if ($this->has($key)) {
                    throw new InvalidArgumentException("Duplicate permission key: {$key}");
                }

                $this->keys[] = $key;
            }
        }
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->keys;
    }

    /** @return list<string> */
    public function expand(string $pattern): array
    {
        if ($this->has($pattern)) {
            return [$pattern];
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);
            $keys = array_values(array_filter($this->keys, fn (string $key): bool => str_starts_with($key, $prefix)));

            if ($keys !== []) {
                return $keys;
            }
        }

        throw new UnknownPermission("Unknown permission: {$pattern}");
    }
}
