<?php

namespace App\Enums;

enum UserRole: string
{
    case Staff = 'staff';
    case Supervisor = 'supervisor';
    case Hr = 'hr';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'บุคลากร',
            self::Supervisor => 'หัวหน้างาน',
            self::Hr => 'งานบุคคล',
            self::Admin => 'ผู้ดูแลระบบ',
        };
    }
}
