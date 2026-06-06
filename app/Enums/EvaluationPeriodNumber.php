<?php

namespace App\Enums;

enum EvaluationPeriodNumber: int
{
    case One = 1;
    case Two = 2;

    public function label(): string
    {
        return match ($this) {
            self::One => 'รอบที่ 1',
            self::Two => 'รอบที่ 2',
        };
    }
}
