<?php

namespace App\Domain\Quotes;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Calculating = 'calculating';
    case RequiresReview = 'requires_review';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function permits(self $next): bool
    {
        return in_array($next->value, match ($this) {
            self::Draft => ['calculating', 'cancelled'],
            self::Calculating => ['requires_review', 'quoted'],
            self::RequiresReview => ['calculating', 'quoted', 'declined', 'cancelled'],
            self::Quoted => ['calculating', 'accepted', 'expired', 'declined', 'cancelled'],
            self::Expired => ['calculating'],
            default => [],
        }, true);
    }
}
