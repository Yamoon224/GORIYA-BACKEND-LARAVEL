<?php

namespace App\Services;

/**
 * Calcul d'un bulletin de paie, sans accès à la base : entrées → lignes et
 * totaux. Tout ce qui dépend d'un pays (cotisations, plafonds, barème d'impôt)
 * vient des paramètres de paie de l'entreprise, jamais de ce code.
 *
 * Déroulé :
 *  1. brut = salaire de base − prorata (jours ouvrés non couverts par le contrat
 *     dans le mois) − absences non rémunérées + primes ;
 *  2. cotisations sur le brut soumis (primes exonérées retirées), plafonnées
 *     ligne par ligne, parts salariale et patronale ;
 *  3. imposable = brut imposable (− cotisations salariales si déductibles),
 *     puis abattement ; impôt par tranches mensuelles ;
 *  4. net = brut − cotisations salariales − impôt − avances − autres retenues ;
 *     coût employeur = brut + cotisations patronales.
 *
 * Tous les montants sont des entiers en FCFA, arrondis ligne par ligne.
 */
class PayrollCalculator
{
    /**
     * @param  array{
     *     baseSalary: int,
     *     businessDays: int,
     *     employedDays: int,
     *     unpaidLeaveDays: int,
     *     bonuses?: list<array{label: string, amount: int, taxable?: bool, subjectToContributions?: bool}>,
     *     deductions?: list<array{label: string, amount: int}>,
     *     advances?: list<array{id: string, label: string, amount: int}>,
     * }  $input
     * @param  array{
     *     contributions: list<array{code: string, label: string, employeeRate?: float|int|null, employerRate?: float|int|null, ceiling?: int|null, employeeFixed?: int|null, employerFixed?: int|null}>,
     *     taxBrackets: list<array{upTo: int|null, rate: float|int}>,
     *     taxLabel: string,
     *     taxableAbatementPercent: float|int,
     *     deductEmployeeContributions: bool,
     * }  $settings
     * @return array{lines: list<array<string, mixed>>, gross: int, employeeContributions: int, employerContributions: int, taxable: int, tax: int, advances: int, otherDeductions: int, net: int, employerCost: int}
     */
    public function compute(array $input, array $settings): array
    {
        $lines = [];
        $base = max(0, (int) $input['baseSalary']);
        $businessDays = max(1, (int) $input['businessDays']);
        $employedDays = max(0, min((int) $input['employedDays'], $businessDays));
        $unpaidDays = max(0, min((int) $input['unpaidLeaveDays'], $employedDays));
        $daily = $base / $businessDays;

        $lines[] = $this->line('BASE', 'Salaire de base', 'EARNING', employeeAmount: $base);

        $notCovered = $businessDays - $employedDays;
        if ($notCovered > 0 && $base > 0) {
            $lines[] = $this->line('PRORATA', "Entrée ou sortie en cours de mois ({$notCovered} j ouvrés non couverts)", 'EARNING', employeeAmount: -(int) round($daily * $notCovered));
        }
        if ($unpaidDays > 0 && $base > 0) {
            $lines[] = $this->line('UNPAID_LEAVE', "Absence non rémunérée ({$unpaidDays} j)", 'EARNING', employeeAmount: -(int) round($daily * $unpaidDays));
        }

        $notSubjectToContributions = 0;
        $notTaxable = 0;
        foreach (array_values($input['bonuses'] ?? []) as $i => $bonus) {
            $amount = max(0, (int) $bonus['amount']);
            $lines[] = $this->line('BONUS_'.($i + 1), $bonus['label'], 'EARNING', employeeAmount: $amount);
            if (! ($bonus['subjectToContributions'] ?? true)) {
                $notSubjectToContributions += $amount;
            }
            if (! ($bonus['taxable'] ?? true)) {
                $notTaxable += $amount;
            }
        }

        $gross = max(0, array_sum(array_column(array_filter($lines, fn ($l) => $l['section'] === 'EARNING'), 'employeeAmount')));

        // Cotisations : rien n'est prélevé sur un bulletin à zéro, pas même les forfaits.
        $contributionBase = max(0, $gross - $notSubjectToContributions);
        $employeeContributions = 0;
        $employerContributions = 0;
        foreach ($settings['contributions'] as $contribution) {
            if ($gross === 0) {
                break;
            }
            $ceiling = $contribution['ceiling'] ?? null;
            $lineBase = $ceiling ? min($contributionBase, (int) $ceiling) : $contributionBase;
            $employeeRate = (float) ($contribution['employeeRate'] ?? 0);
            $employerRate = (float) ($contribution['employerRate'] ?? 0);
            $employee = (int) round($lineBase * $employeeRate / 100) + (int) ($contribution['employeeFixed'] ?? 0);
            $employer = (int) round($lineBase * $employerRate / 100) + (int) ($contribution['employerFixed'] ?? 0);
            if ($employee === 0 && $employer === 0) {
                continue;
            }
            $employeeContributions += $employee;
            $employerContributions += $employer;
            $lines[] = $this->line(
                $contribution['code'],
                $contribution['label'],
                'CONTRIBUTION',
                base: $lineBase,
                employeeRate: $employeeRate ?: null,
                employerRate: $employerRate ?: null,
                employeeAmount: $employee,
                employerAmount: $employer,
            );
        }

        $taxableBeforeAbatement = max(0, $gross - $notTaxable - ($settings['deductEmployeeContributions'] ? $employeeContributions : 0));
        $taxable = (int) round($taxableBeforeAbatement * (1 - ((float) $settings['taxableAbatementPercent']) / 100));
        $tax = $this->progressiveTax($taxable, $settings['taxBrackets']);
        if ($tax > 0) {
            $lines[] = $this->line('TAX', $settings['taxLabel'] ?: 'Impôt sur salaire', 'TAX', base: $taxable, employeeAmount: $tax);
        }

        $advances = 0;
        foreach ($input['advances'] ?? [] as $advance) {
            $amount = max(0, (int) $advance['amount']);
            $advances += $amount;
            $lines[] = $this->line('ADVANCE', "Avance sur salaire — {$advance['label']}", 'DEDUCTION', employeeAmount: $amount);
        }

        $otherDeductions = 0;
        foreach (array_values($input['deductions'] ?? []) as $i => $deduction) {
            $amount = max(0, (int) $deduction['amount']);
            $otherDeductions += $amount;
            $lines[] = $this->line('DEDUCTION_'.($i + 1), $deduction['label'], 'DEDUCTION', employeeAmount: $amount);
        }

        return [
            'lines' => $lines,
            'gross' => $gross,
            'employeeContributions' => $employeeContributions,
            'employerContributions' => $employerContributions,
            'taxable' => $taxable,
            'tax' => $tax,
            'advances' => $advances,
            'otherDeductions' => $otherDeductions,
            'net' => $gross - $employeeContributions - $tax - $advances - $otherDeductions,
            'employerCost' => $gross + $employerContributions,
        ];
    }

    /**
     * Impôt par tranches : chaque tranche taxe la part du revenu comprise entre
     * le plafond précédent et le sien.
     *
     * @param  list<array{upTo: int|null, rate: float|int}>  $brackets
     */
    public function progressiveTax(int $taxable, array $brackets): int
    {
        $tax = 0.0;
        $lower = 0;
        foreach ($brackets as $bracket) {
            if ($taxable <= $lower) {
                break;
            }
            $upper = $bracket['upTo'] ?? PHP_INT_MAX;
            $tax += (min($taxable, $upper) - $lower) * ((float) $bracket['rate']) / 100;
            $lower = $upper;
        }

        return (int) round($tax);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(
        string $code,
        string $label,
        string $section,
        ?int $base = null,
        ?float $employeeRate = null,
        ?float $employerRate = null,
        int $employeeAmount = 0,
        ?int $employerAmount = null,
    ): array {
        return compact('code', 'label', 'section', 'base', 'employeeRate', 'employerRate', 'employeeAmount', 'employerAmount');
    }
}
