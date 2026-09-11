<?php

namespace App\Enums;

/** Documents RH que Goriya rédige à partir de la fiche employé. */
enum HrDocumentTemplate: string
{
    /** Emploi en cours (ou passé) : se remet à tout moment. */
    case WORK_CERTIFICATE = 'WORK_CERTIFICATE';
    /** Salaire brut mensuel et dernières rémunérations versées. */
    case SALARY_CERTIFICATE = 'SALARY_CERTIFICATE';
    /** Remis à la fin du contrat : période d'emploi et poste occupé. */
    case EMPLOYMENT_CERTIFICATE = 'EMPLOYMENT_CERTIFICATE';

    public function title(): string
    {
        return match ($this) {
            self::WORK_CERTIFICATE => 'Attestation de travail',
            self::SALARY_CERTIFICATE => 'Attestation de salaire',
            self::EMPLOYMENT_CERTIFICATE => 'Certificat de travail',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::WORK_CERTIFICATE => 'attestation-travail',
            self::SALARY_CERTIFICATE => 'attestation-salaire',
            self::EMPLOYMENT_CERTIFICATE => 'certificat-travail',
        };
    }

    /** Demande RH à laquelle ce document répond. */
    public function answers(HrRequestType $type): bool
    {
        return match ($this) {
            self::SALARY_CERTIFICATE => $type === HrRequestType::SALARY_CERTIFICATE,
            self::WORK_CERTIFICATE, self::EMPLOYMENT_CERTIFICATE => $type === HrRequestType::WORK_CERTIFICATE,
        };
    }
}
