<?php

namespace App\Enums;

/** Format d'un entretien de recrutement. */
enum RecruitmentInterviewType: string
{
    case PHONE = 'PHONE';
    case VIDEO = 'VIDEO';
    case ONSITE = 'ONSITE';

    /** Complément de « un entretien … » dans les notifications au candidat. */
    public function label(): string
    {
        return match ($this) {
            self::PHONE => 'téléphonique',
            self::VIDEO => 'sur GORIYA Meet',
            self::ONSITE => 'en présentiel',
        };
    }
}
