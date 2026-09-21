<?php

namespace App\Enums;

enum UserRole: string
{
    case DisbursingOfficer = 'disbursing_officer';
    case BudgetOfficer = 'budget_officer';
    case FinancialAnalyst = 'financial_analyst';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::DisbursingOfficer => 'Disbursing Officer',
            self::BudgetOfficer => 'Budget Officer',
            self::FinancialAnalyst => 'Financial Analyst',
            self::Admin => 'Administrator',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
