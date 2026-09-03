<?php

namespace App\Http\Concerns;

use Illuminate\Database\QueryException;

/**
 * Mirroir de la gestion des violations de contrainte d'unicité dans les
 * services NestJS users/companies : messages français par champ, générique
 * en dernier recours.
 *
 * Deux SGBD sont en jeu et ne signalent PAS l'unicité de la même façon :
 *  - PostgreSQL : SQLSTATE 23505 (unique_violation), détail
 *    « Key (email)=(a@b.c) already exists. »
 *  - MySQL/MariaDB (SGBD de production) : SQLSTATE 23000 — générique à toutes
 *    les violations d'intégrité — avec le code pilote 1062, détail
 *    « Duplicate entry 'a@b.c' for key 'users_email_unique' ».
 *
 * Ne tester que 23505 laissait donc l'inscription entreprise renvoyer un 500
 * opaque en production (email déjà utilisé) au lieu du message métier.
 */
trait HandlesUniqueViolations
{
    /** SQLSTATE PostgreSQL : unique_violation. */
    private const PGSQL_UNIQUE_VIOLATION = '23505';

    /** SQLSTATE MySQL/SQLite : integrity_constraint_violation (toutes causes). */
    private const INTEGRITY_VIOLATION = '23000';

    /** Code pilote MySQL : ER_DUP_ENTRY — la seule cause 23000 qui nous intéresse. */
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    /**
     * @param  array<string, string>  $fieldMessages  ex: ['email' => 'Cette adresse email est déjà utilisée']
     */
    protected function abortOnUniqueViolation(QueryException $e, array $fieldMessages, string $default = 'Valeur unique déjà utilisée'): never
    {
        if (! $this->isUniqueViolation($e)) {
            throw $e;
        }

        $haystack = $this->violatedConstraintHaystack($e);

        foreach ($fieldMessages as $field => $message) {
            if (str_contains($haystack, $field)) {
                abort(400, $message);
            }
        }

        abort(400, $default);
    }

    /**
     * 23000 couvre TOUTES les violations d'intégrité (clé étrangère, NOT NULL,
     * CHECK…) : on ne le retient que si le pilote confirme un doublon, sinon on
     * renverrait « valeur déjà utilisée » sur une erreur qui n'a rien à voir.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState === self::PGSQL_UNIQUE_VIOLATION) {
            return true;
        }

        if ($sqlState !== self::INTEGRITY_VIOLATION) {
            return false;
        }

        // MySQL/MariaDB : ER_DUP_ENTRY.
        if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_DUPLICATE_ENTRY) {
            return true;
        }

        // SQLite (tests, dev) : le code 19 est générique, seul le message
        // distingue la contrainte d'unicité.
        return str_contains((string) ($e->errorInfo[2] ?? ''), 'UNIQUE constraint failed');
    }

    /**
     * Isole la partie du message driver qui nomme la contrainte, pour ne pas
     * faire correspondre un champ sur la *valeur* dupliquée : l'entreprise
     * « Phone Services » ne doit pas produire « Ce numéro de téléphone est
     * déjà utilisé ». MySQL nomme la clé après « for key », PostgreSQL après
     * « Key ( », SQLite après « UNIQUE constraint failed: » — à défaut on
     * retombe sur le détail complet.
     */
    private function violatedConstraintHaystack(QueryException $e): string
    {
        $detail = (string) ($e->errorInfo[2] ?? '');

        if (preg_match("/for key ['`\"]?([^'`\"]+)/i", $detail, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/Key \(([^)]+)\)/i', $detail, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/UNIQUE constraint failed:\s*(.+)$/i', $detail, $m) === 1) {
            return $m[1];
        }

        return $detail;
    }
}
