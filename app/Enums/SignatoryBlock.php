<?php

namespace App\Enums;

enum SignatoryBlock: string
{
    case PreparedBy = 'prepared_by';
    case CertifiedCorrect = 'certified_correct';
    case DisbursingOfficer = 'disbursing_officer';

    public function label(): string
    {
        return match ($this) {
            self::PreparedBy => 'Prepared by',
            self::CertifiedCorrect => 'Certified Correct',
            self::DisbursingOfficer => 'Disbursing Officer',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $b): string => $b->value, self::cases());
    }
}
