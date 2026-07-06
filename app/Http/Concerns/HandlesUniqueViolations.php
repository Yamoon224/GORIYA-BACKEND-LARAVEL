<?php

namespace App\Http\Concerns;

use Illuminate\Database\QueryException;

/**
 * Mirroir de la gestion du code Postgres 23505 (unique_violation) dans les
 * services NestJS users/companies : messages français par champ, générique
 * en dernier recours.
 */
trait HandlesUniqueViolations
{
    /**
     * @param  array<string, string>  $fieldMessages  ex: ['email' => 'Cette adresse email est déjà utilisée']
     */
    protected function abortOnUniqueViolation(QueryException $e, array $fieldMessages, string $default = 'Valeur unique déjà utilisée'): never
    {
        if (($e->errorInfo[0] ?? null) !== '23505') {
            throw $e;
        }

        $detail = $e->errorInfo[2] ?? '';

        foreach ($fieldMessages as $field => $message) {
            if (str_contains($detail, $field)) {
                abort(400, $message);
            }
        }

        abort(400, $default);
    }
}
