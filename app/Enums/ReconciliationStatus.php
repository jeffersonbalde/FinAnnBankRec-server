<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    case Draft = 'draft';
    case ForReview = 'for_review';
    case Certified = 'certified';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ForReview => 'For Review',
            self::Certified => 'Certified',
            self::Returned => 'Returned for revision',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], strict: true);
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft, self::Returned => [self::ForReview],
            self::ForReview => [self::Certified, self::Returned],
            self::Certified => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), strict: true);
    }
}
