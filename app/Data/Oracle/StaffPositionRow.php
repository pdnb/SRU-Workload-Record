<?php

namespace App\Data\Oracle;

readonly class StaffPositionRow
{
    /**
     * @param  string|null  $employeeId  PROMIS STAFFID (VW_HR_STAFF.ID)
     * @param  string|null  $altEmail  VNMIS HR_STAFF.EVAPPEMAILACCOUNT, used when the PROMIS email is personal
     */
    public function __construct(
        public ?string $employeeId,
        public ?string $email,
        public string $positionCode,
        public ?int $fiscalYear = null,
        public ?string $altEmail = null,
    ) {}
}
