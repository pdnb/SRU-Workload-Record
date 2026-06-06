<?php

namespace App\Data;

readonly class WorkRecordScoreSummary
{
    public function __construct(
        public float $earned,
        public ?float $maximum,
    ) {}

    public function hasEarned(): bool
    {
        return $this->earned > 0;
    }

    public function hasMaximum(): bool
    {
        return $this->maximum !== null;
    }
}
