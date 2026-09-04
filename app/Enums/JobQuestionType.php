<?php

namespace App\Enums;

/**
 * Types de questions qu'une entreprise peut ajouter à une offre d'emploi et
 * auxquelles le candidat répond dans le wizard de candidature.
 */
enum JobQuestionType: string
{
    case TEXT = 'TEXT';
    case TEXTAREA = 'TEXTAREA';
    case NUMBER = 'NUMBER';
    case BOOLEAN = 'BOOLEAN';
    case SINGLE_CHOICE = 'SINGLE_CHOICE';
    case MULTI_CHOICE = 'MULTI_CHOICE';

    /**
     * Types dont la réponse est choisie parmi `options` — les seuls pour
     * lesquels la liste d'options est obligatoire et vérifiée à la réponse.
     *
     * @return list<self>
     */
    public static function choiceTypes(): array
    {
        return [self::SINGLE_CHOICE, self::MULTI_CHOICE];
    }

    public function expectsOptions(): bool
    {
        return in_array($this, self::choiceTypes(), true);
    }
}
