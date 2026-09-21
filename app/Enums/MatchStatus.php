<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Unmatched = 'unmatched';
    case Auto = 'auto';
    case Manual = 'manual';
    case Ignored = 'ignored';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
