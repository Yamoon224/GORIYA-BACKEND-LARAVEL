<?php

namespace App\Enums;

/**
 * Les valeurs contiennent volontairement les sous-chaînes "message"/
 * "application" — c'est sur ce texte que standard/notifications/page.tsx
 * (mapType()) et entreprise bucketisent le type en catégorie d'affichage.
 */
enum NotificationType: string
{
    case MESSAGE = 'MESSAGE';
    case APPLICATION_STATUS = 'APPLICATION_STATUS';
    case SYSTEM = 'SYSTEM';
}
