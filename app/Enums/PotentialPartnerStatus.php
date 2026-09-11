<?php

namespace App\Enums;

/**
 * Pipeline de prospection d'un partenaire potentiel (module Potentiels
 * Partenaires). DO_NOT_CONTACT couvre à la fois le désabonnement via le lien
 * d'une campagne et un opt-out saisi manuellement par un admin.
 */
enum PotentialPartnerStatus: string
{
    case NEW = 'new';
    case CONTACTED = 'contacted';
    case RESPONDED = 'responded';
    case INTERESTED = 'interested';
    case NOT_INTERESTED = 'not_interested';
    case CONVERTED = 'converted';
    case DO_NOT_CONTACT = 'do_not_contact';
}
