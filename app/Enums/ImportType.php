<?php

namespace App\Enums;

enum ImportType: string
{
    case Rci = 'rci';
    case BankStatement = 'bank_statement';

    public function label(): string
    {
        return match ($this) {
            self::Rci => 'Report of Checks Issued',
            self::BankStatement => 'Bank Statement',
        };
    }
}
