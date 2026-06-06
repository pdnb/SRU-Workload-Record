<?php

namespace App\Enums;

enum PeriodStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'ร่าง',
            self::Open => 'เปิดรับ',
            self::Closed => 'ปิดแล้ว',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
