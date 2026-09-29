<?php

namespace App\Data\Oracle;

readonly class PositionRow
{
    public function __construct(
        public string $externalId,
        public string $code,
        public string $name,
        public bool $active = true,
    ) {}
}
