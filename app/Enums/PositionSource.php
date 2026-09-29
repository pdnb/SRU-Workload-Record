<?php

namespace App\Enums;

enum PositionSource: string
{
    case Oracle = 'oracle';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Oracle => 'PCM',
            self::Manual => 'กำหนดเอง',
        };
    }
}
