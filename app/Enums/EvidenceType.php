<?php

namespace App\Enums;

enum EvidenceType: string
{
    case Url = 'url';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Url => 'ลิงก์',
            self::File => 'ไฟล์',
        };
    }
}
