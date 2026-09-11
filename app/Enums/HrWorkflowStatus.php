<?php

namespace App\Enums;

/**
 * Cycle de vie commun aux congés et aux demandes RH.
 *
 * IN_PROGRESS ne concerne que les demandes (une attestation « en cours de
 * rédaction ») : un congé passe directement de PENDING à une décision.
 * APPROVED, REJECTED et CANCELLED sont finaux : un congé approuvé ne
 * s'annule plus, il se supprime (cf. EmployeeLeaveService).
 */
enum HrWorkflowStatus: string
{
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
}
