<?php

namespace Seisigma\AccessProfiles\Templates;

final readonly class TemplateDefinition
{
    /**
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description = null,
        public array $allow = [],
        public array $deny = [],
        public ?string $requiresCondition = null,
    ) {}
}
