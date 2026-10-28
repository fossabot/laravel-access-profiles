<?php

namespace Seisigma\AccessProfiles\Exceptions;

use RuntimeException;

/**
 * A builder refused to write. `$reason` is a stable code hosts can match on;
 * a host rule rejects with `rule:<name>`.
 */
class GrantRejected extends RuntimeException
{
    public const MISSING_GRANTOR = 'missing_grantor';

    public const MISSING_HOLDER = 'missing_holder';

    public const MISSING_TENANT = 'missing_tenant';

    public const NO_SCOPES = 'no_scopes';

    public const INVALID_WINDOW = 'invalid_window';

    public const UNKNOWN_PROFILE = 'unknown_profile';

    public const PROFILE_OUTSIDE_TENANT = 'profile_outside_tenant';

    public const NOT_A_TENANT = 'not_a_tenant';

    public const SCOPE_OUTSIDE_TENANT = 'scope_outside_tenant';

    public const SCOPE_IS_TENANT = 'scope_is_tenant';

    public const DUPLICATE_ASSIGNMENT = 'duplicate_assignment';

    public const ESCALATION = 'escalation';

    public const SELF_ASSIGNMENT = 'self_assignment';

    public const LAST_HOLDER = 'last_holder';

    public const ALREADY_REVOKED = 'already_revoked';

    public const SYSTEM_TEMPLATE = 'system_template';

    public const DUPLICATE_PROFILE = 'duplicate_profile';

    public const PROFILE_IN_USE = 'profile_in_use';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Grant rejected: '.$reason);
    }

    public static function rule(string $name): self
    {
        return new self('rule:'.$name);
    }
}
