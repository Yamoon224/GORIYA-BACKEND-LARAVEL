<?php

namespace App\Enums;

/**
 * Rangement des documents RH. `CONTRACT` et `PAYROLL` accueillent aussi, en
 * lecture seule, les contrats signés et les bulletins validés (voir
 * EmployeeDocumentService::listForCompany).
 */
enum EmployeeDocumentCategory: string
{
    /** Pièce d'identité, titre de séjour. */
    case IDENTITY = 'IDENTITY';
    /** CV, diplômes, certifications. */
    case RESUME_DIPLOMA = 'RESUME_DIPLOMA';
    /** Contrats, avenants, promesses d'embauche. */
    case CONTRACT = 'CONTRACT';
    /** Attestations et certificats. */
    case CERTIFICATE = 'CERTIFICATE';
    /** Paie, CNPS, fiscalité. */
    case PAYROLL = 'PAYROLL';
    /** Visite médicale, aptitude. */
    case MEDICAL = 'MEDICAL';
    case TRAINING = 'TRAINING';
    /** Courriers, avertissements, sanctions. */
    case DISCIPLINARY = 'DISCIPLINARY';
    /** Règlement intérieur, chartes, procédures — documents d'entreprise. */
    case POLICY = 'POLICY';
    case OTHER = 'OTHER';
}
