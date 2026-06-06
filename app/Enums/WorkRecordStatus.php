<?php

namespace App\Enums;

enum WorkRecordStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'ฉบับร่าง',
            self::Submitted => 'ส่งแล้ว',
            self::Approved => 'อนุมัติแล้ว',
            self::Rejected => 'ส่งกลับแก้ไข',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Submitted => 'blue',
            self::Approved => 'green',
            self::Rejected => 'red',
        };
    }
}
