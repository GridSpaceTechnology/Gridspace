<?php

namespace App\Enums;

enum CandidateRecommendationFeedbackType: string
{
    case Relevant = 'relevant';
    case NotRelevant = 'not_relevant';
    case NotInterested = 'not_interested';
    case AlreadyApplied = 'already_applied';
    case WrongRole = 'wrong_role';
    case WrongLocation = 'wrong_location';
    case WrongExperience = 'wrong_experience';

    public function isNegativeSignal(): bool
    {
        return in_array($this, [
            self::NotRelevant,
            self::NotInterested,
            self::WrongRole,
            self::WrongLocation,
            self::WrongExperience,
        ], true);
    }
}
