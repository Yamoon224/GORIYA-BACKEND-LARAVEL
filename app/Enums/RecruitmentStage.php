<?php

namespace App\Enums;

/**
 * Étape d'un candidat dans le pipeline de recrutement (Services RH).
 *
 * Plus fine que CandidatureStatus, que suit le candidat : chaque étape
 * correspond à un statut public, mis à jour en même temps (et donc notifié).
 * « Embauché » ne se choisit pas : il découle de la création de la fiche employé.
 */
enum RecruitmentStage: string
{
    case NEW = 'NEW';
    case SCREENING = 'SCREENING';
    case INTERVIEW = 'INTERVIEW';
    case OFFER = 'OFFER';
    case HIRED = 'HIRED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Nouveau',
            self::SCREENING => 'Présélection',
            self::INTERVIEW => 'Entretien',
            self::OFFER => 'Offre',
            self::HIRED => 'Embauché',
            self::REJECTED => 'Refusé',
        };
    }

    /** Statut public correspondant, celui que voit le candidat. */
    public function candidatureStatus(): CandidatureStatus
    {
        return match ($this) {
            self::NEW => CandidatureStatus::EN_ATTENTE,
            self::SCREENING, self::INTERVIEW => CandidatureStatus::EN_COURS,
            self::OFFER, self::HIRED => CandidatureStatus::APPROUVEE,
            self::REJECTED => CandidatureStatus::REJETEE,
        };
    }

    /** Étape déduite d'un statut changé hors du pipeline (page Candidatures). */
    public static function fromCandidatureStatus(CandidatureStatus $status): self
    {
        return match ($status) {
            CandidatureStatus::EN_ATTENTE => self::NEW,
            CandidatureStatus::EN_COURS => self::SCREENING,
            CandidatureStatus::APPROUVEE => self::OFFER,
            CandidatureStatus::REJETEE => self::REJECTED,
        };
    }
}
