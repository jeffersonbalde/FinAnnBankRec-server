<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Outstanding = 'outstanding';
    case Cleared = 'cleared';
    case Cancelled = 'cancelled';
    case Stale = 'stale';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
