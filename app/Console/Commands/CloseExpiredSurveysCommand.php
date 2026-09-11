<?php

namespace App\Console\Commands;

use App\Services\EmployeeSurveyService;
use Illuminate\Console\Command;

/**
 * Clôture automatiquement les évaluations (EmployeeSurvey) actives dont
 * l'échéance (`due_date`) est dépassée — planifiée quotidienne, voir
 * routes/console.php. Le changement de statut lui-même vit dans
 * EmployeeSurveyService::closeExpired() pour rester testable sans Artisan.
 */
class CloseExpiredSurveysCommand extends Command
{
    protected $signature = 'surveys:close-expired';

    protected $description = "Clôture les évaluations actives dont l'échéance est dépassée";

    public function handle(EmployeeSurveyService $surveys): int
    {
        $count = $surveys->closeExpired();

        $this->info("{$count} évaluation(s) clôturée(s).");

        return self::SUCCESS;
    }
}
