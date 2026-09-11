<?php

namespace App\Services;

use App\Models\PayrollSetting;
use App\Models\User;

/**
 * Paramètres de paie d'une entreprise : cotisations sociales et barème d'impôt.
 *
 * Les valeurs par défaut reprennent les principaux prélèvements ivoiriens
 * (CNPS, CMU, FDFP, ITS). Elles sont INDICATIVES : taux, plafonds et tranches
 * évoluent avec les lois de finances et varient selon l'activité (taux
 * accidents du travail notamment). L'interface le dit et invite à les faire
 * valider ; l'entreprise peut tout modifier.
 */
class PayrollSettingsService
{
    /**
     * @return array{preset: string, taxLabel: string, taxableAbatementPercent: float, deductEmployeeContributions: bool, contributions: list<array<string, mixed>>, taxBrackets: list<array{upTo: int|null, rate: float}>}
     */
    public static function defaults(): array
    {
        return [
            'preset' => 'CI',
            'taxLabel' => 'ITS',
            'taxableAbatementPercent' => 0.0,
            'deductEmployeeContributions' => false,
            'contributions' => [
                ['code' => 'CNPS_RETRAITE', 'label' => 'CNPS — Retraite', 'employeeRate' => 6.3, 'employerRate' => 7.7, 'ceiling' => 1647315, 'employeeFixed' => 0, 'employerFixed' => 0],
                ['code' => 'CNPS_PF', 'label' => 'CNPS — Prestations familiales', 'employeeRate' => 0, 'employerRate' => 5.75, 'ceiling' => 70000, 'employeeFixed' => 0, 'employerFixed' => 0],
                ['code' => 'CNPS_MAT', 'label' => 'CNPS — Assurance maternité', 'employeeRate' => 0, 'employerRate' => 0.75, 'ceiling' => 70000, 'employeeFixed' => 0, 'employerFixed' => 0],
                ['code' => 'CNPS_AT', 'label' => 'CNPS — Accidents du travail', 'employeeRate' => 0, 'employerRate' => 2, 'ceiling' => 70000, 'employeeFixed' => 0, 'employerFixed' => 0],
                ['code' => 'CMU', 'label' => 'Couverture maladie universelle', 'employeeRate' => 0, 'employerRate' => 0, 'ceiling' => null, 'employeeFixed' => 1000, 'employerFixed' => 1000],
                ['code' => 'FDFP', 'label' => 'FDFP — Apprentissage et formation continue', 'employeeRate' => 0, 'employerRate' => 1.6, 'ceiling' => null, 'employeeFixed' => 0, 'employerFixed' => 0],
            ],
            'taxBrackets' => [
                ['upTo' => 75000, 'rate' => 0.0],
                ['upTo' => 240000, 'rate' => 16.0],
                ['upTo' => 800000, 'rate' => 21.0],
                ['upTo' => 2400000, 'rate' => 24.0],
                ['upTo' => 8000000, 'rate' => 28.0],
                ['upTo' => null, 'rate' => 32.0],
            ],
        ];
    }

    /**
     * Paramètres en vigueur, au format de l'API et du calculateur. Tant que
     * l'entreprise n'a rien modifié, ce sont les valeurs par défaut (rien n'est
     * écrit en base à la lecture).
     *
     * @return array<string, mixed>
     */
    public function forCompany(string $companyId): array
    {
        $setting = PayrollSetting::where('company_id', $companyId)->first();
        if (! $setting) {
            return self::defaults() + ['updatedAt' => null];
        }

        return [
            'preset' => $setting->preset,
            'taxLabel' => $setting->tax_label,
            'taxableAbatementPercent' => (float) $setting->taxable_abatement_percent,
            'deductEmployeeContributions' => (bool) $setting->deduct_employee_contributions,
            'contributions' => $setting->contributions,
            'taxBrackets' => $setting->tax_brackets,
            'updatedAt' => $setting->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Champs validés (UpdatePayrollSettingsRequest)
     * @return array<string, mixed>
     */
    public function update(string $companyId, User $user, array $data): array
    {
        $brackets = array_values($data['taxBrackets']);
        $this->assertBrackets($brackets);

        $contributions = array_map(fn (array $c) => [
            'code' => strtoupper(trim($c['code'])),
            'label' => trim($c['label']),
            'employeeRate' => (float) ($c['employeeRate'] ?? 0),
            'employerRate' => (float) ($c['employerRate'] ?? 0),
            'ceiling' => isset($c['ceiling']) ? (int) $c['ceiling'] : null,
            'employeeFixed' => (int) ($c['employeeFixed'] ?? 0),
            'employerFixed' => (int) ($c['employerFixed'] ?? 0),
        ], array_values($data['contributions']));

        PayrollSetting::updateOrCreate(['company_id' => $companyId], [
            'preset' => 'CUSTOM',
            'contributions' => $contributions,
            'tax_brackets' => array_map(fn (array $b) => [
                'upTo' => isset($b['upTo']) ? (int) $b['upTo'] : null,
                'rate' => (float) $b['rate'],
            ], $brackets),
            'tax_label' => trim($data['taxLabel']),
            'taxable_abatement_percent' => (float) ($data['taxableAbatementPercent'] ?? 0),
            'deduct_employee_contributions' => (bool) ($data['deductEmployeeContributions'] ?? false),
            'updated_by' => $user->id,
        ]);

        return $this->forCompany($companyId);
    }

    /** Revient aux valeurs par défaut (la ligne est supprimée : plus rien de personnalisé). */
    public function reset(string $companyId): array
    {
        PayrollSetting::where('company_id', $companyId)->delete();

        return $this->forCompany($companyId);
    }

    /**
     * Tranches ordonnées par plafond croissant, seule la dernière sans plafond.
     *
     * @param  list<array{upTo?: int|null, rate: float|int}>  $brackets
     */
    private function assertBrackets(array $brackets): void
    {
        $count = count($brackets);
        $previous = 0;
        foreach ($brackets as $index => $bracket) {
            $upTo = $bracket['upTo'] ?? null;
            if ($upTo === null && $index !== $count - 1) {
                abort(400, 'Seule la dernière tranche du barème peut être sans plafond.');
            }
            if ($upTo !== null && (int) $upTo <= $previous) {
                abort(400, 'Les plafonds des tranches doivent être croissants.');
            }
            $previous = $upTo !== null ? (int) $upTo : $previous;
        }
        if (($brackets[$count - 1]['upTo'] ?? null) !== null) {
            abort(400, 'La dernière tranche du barème doit être sans plafond.');
        }
    }
}
