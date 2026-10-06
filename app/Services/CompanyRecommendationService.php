<?php

namespace App\Services;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\JobOffer;
use Illuminate\Support\Carbon;

/**
 * Les 3 recommandations du jour affichées sur le tableau de bord entreprise
 * (carte « Recommandations IA du jour ») — jusqu'ici un texte figé
 * ("35% plus de candidats qualifiés", "mardi 10h"...) qui ne reflétait
 * jamais les chiffres réels de l'entreprise. Chaque recommandation est
 * calculée ici à partir de ses propres données ; `AnthropicDashboardInsightsService`
 * ne fait que reformuler ces phrases déjà correctes, jamais inventer un chiffre.
 */
class CompanyRecommendationService
{
    private const JOURS_FR = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /**
     * @return array{applications: string, bestSlot: string, pool: string}
     */
    public function metrics(string $companyId): array
    {
        return [
            'applications' => $this->applicationsInsight($companyId),
            'bestSlot' => $this->bestSlotInsight($companyId),
            'pool' => $this->poolInsight($companyId),
        ];
    }

    private function applicationsInsight(string $companyId): string
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonth()->startOfMonth();
        $lastMonthEnd = $monthStart->copy()->subSecond();

        $baseQuery = fn () => Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $companyId));

        $countThisMonth = $baseQuery()->where('applied_date', '>=', $monthStart)->count();
        $countLastMonth = $baseQuery()->whereBetween('applied_date', [$lastMonthStart, $lastMonthEnd])->count();

        $avgScoreThisMonth = $baseQuery()->where('applied_date', '>=', $monthStart)->whereNotNull('score')->avg('score');
        $avgScoreLastMonth = $baseQuery()->whereBetween('applied_date', [$lastMonthStart, $lastMonthEnd])->whereNotNull('score')->avg('score');

        if ($avgScoreThisMonth !== null && $avgScoreLastMonth !== null && $avgScoreLastMonth > 0) {
            $pct = round((($avgScoreThisMonth - $avgScoreLastMonth) / $avgScoreLastMonth) * 100);
            if ($pct > 0) {
                return "Vos candidatures reçues ce mois-ci sont en moyenne {$pct}% plus qualifiées (score Goriya) que le mois dernier.";
            }
            if ($pct < 0) {
                return 'Le score Goriya moyen de vos candidatures a baissé de '.abs($pct)." % ce mois-ci par rapport au mois dernier.";
            }

            return 'Le score Goriya moyen de vos candidatures est stable ce mois-ci ('.round($avgScoreThisMonth).'/100).';
        }

        if ($countLastMonth > 0) {
            $pct = round((($countThisMonth - $countLastMonth) / $countLastMonth) * 100);
            if ($pct > 0) {
                return "Vous avez reçu {$pct}% de candidatures en plus ce mois-ci par rapport au mois dernier ({$countThisMonth} contre {$countLastMonth}).";
            }
            if ($pct < 0) {
                return "Vos candidatures sont en baisse de ".abs($pct)."% ce mois-ci par rapport au mois dernier ({$countThisMonth} contre {$countLastMonth}).";
            }

            return "Vous avez reçu autant de candidatures ce mois-ci que le mois dernier ({$countThisMonth}).";
        }

        if ($countThisMonth > 0) {
            return "Vous avez reçu {$countThisMonth} candidature".($countThisMonth > 1 ? 's' : '')." ce mois-ci.";
        }

        return "Aucune candidature reçue ce mois-ci — vérifiez la visibilité de vos offres actives.";
    }

    private function bestSlotInsight(string $companyId): string
    {
        $offres = JobOffer::where('company_id', $companyId)
            ->whereNotNull('publish_date')
            ->get(['publish_date', 'applicants']);

        $parJour = $offres->groupBy(fn (JobOffer $o) => Carbon::parse($o->publish_date)->isoWeekday());

        if ($offres->count() >= 3 && $parJour->count() >= 2) {
            $meilleurJour = $parJour
                ->map(fn ($groupe) => $groupe->avg('applicants'))
                ->sortDesc()
                ->keys()
                ->first();
            $moyenne = round($parJour[$meilleurJour]->avg('applicants'), 1);

            return ucfirst(self::JOURS_FR[$meilleurJour])." est votre meilleur jour de publication : vos offres y reçoivent en moyenne {$moyenne} candidature".($moyenne > 1 ? 's' : '').'.';
        }

        $offresPerimees = JobOffer::where('company_id', $companyId)
            ->where('status', 'ACTIVE')
            ->where('publish_date', '<=', now()->subDays(30))
            ->count();

        if ($offresPerimees > 0) {
            return "{$offresPerimees} de vos offres actives n'ont pas été renouvelées depuis plus de 30 jours — une republication peut relancer leur visibilité.";
        }

        return "Publiez régulièrement pour faire émerger votre meilleur créneau de publication — il nous faut encore quelques offres pour le calculer.";
    }

    private function poolInsight(string $companyId): string
    {
        $company = Company::find($companyId);
        $depuis = now()->subDays(60);

        $dejaPostule = Candidature::whereHas('jobOffer', fn ($q) => $q->where('company_id', $companyId))
            ->pluck('user_id')
            ->filter()
            ->unique();

        if ($company?->sector) {
            $interessesSecteur = Candidature::whereHas('jobOffer.company', fn ($q) => $q->where('sector', $company->sector)->where('id', '!=', $companyId))
                ->where('applied_date', '>=', $depuis)
                ->pluck('user_id')
                ->filter()
                ->unique();

            $potentiels = $interessesSecteur->diff($dejaPostule)->count();

            if ($potentiels > 0) {
                return "{$potentiels} candidat".($potentiels > 1 ? 's' : '')." actif".($potentiels > 1 ? 's' : '')." dans le secteur {$company->sector} n'ont pas encore postulé chez vous.";
            }
        }

        $actifsRecents = Candidature::where('applied_date', '>=', $depuis)
            ->pluck('user_id')
            ->filter()
            ->unique();

        $potentiels = $actifsRecents->diff($dejaPostule)->count();

        if ($potentiels > 0) {
            return "{$potentiels} candidat".($potentiels > 1 ? 's' : '')." actif".($potentiels > 1 ? 's' : '')." sur Goriya n'ont pas encore postulé chez vous.";
        }

        return "Publiez une nouvelle offre pour élargir votre vivier de candidats potentiels.";
    }
}
