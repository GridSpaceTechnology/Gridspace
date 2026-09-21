<?php

namespace App\Enums;

enum EmployerRecommendationFeedbackType: string
{
    case RelevantCandidate = 'relevant_candidate';
    case NotRelevant = 'not_relevant';
    case SkillsMismatch = 'skills_mismatch';
    case ExperienceMismatch = 'experience_mismatch';
    case RoleMismatch = 'role_mismatch';
    case AlreadyContacted = 'already_contacted';
    case NotInterested = 'not_interested';
}
