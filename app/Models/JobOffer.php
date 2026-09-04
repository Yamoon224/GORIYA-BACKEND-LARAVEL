<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Concerns\HasSlug;
use App\Concerns\HasUuid;
use App\Enums\JobExperienceType;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class JobOffer extends Model
{
    use Auditable, HasFactory, HasSlug, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'location',
        'salary',
        'type',
        'experience',
        'description',
        'benefits',
        'requirements',
        'status',
        'publish_date',
        'end_date',
        'applicants',
        'company_id',
        'image',
        'remote',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'experience' => JobExperienceType::class,
            'status' => JobStatus::class,
            'publish_date' => 'date',
            'end_date' => 'date',
            'requirements' => 'array',
            'remote' => 'boolean',
        ];
    }

    /**
     * Ordre d'affichage des offres : forfait de l'entreprise d'abord (le plan
     * le plus cher remonte en tête — c'est la contrepartie de l'abonnement),
     * puis la date d'ajout la plus récente.
     *
     * Le rang du plan se lit sur l'abonnement ACTIF de l'utilisateur
     * ENTREPRISE rattaché à la société (user_subscriptions -> users.company_id).
     * Une entreprise sans abonnement retombe sur 0, donc en fin de liste,
     * juste derrière l'offre gratuite (prix 0 également) — les deux se
     * départagent alors à la date.
     */
    public function scopeOrderByPlanThenRecency(Builder $query): Builder
    {
        $rangDuPlan = DB::table('user_subscriptions')
            ->join('users', 'users.id', '=', 'user_subscriptions.user_id')
            ->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'user_subscriptions.plan_id')
            ->whereColumn('users.company_id', 'job_offers.company_id')
            ->where('user_subscriptions.status', SubscriptionStatus::ACTIVE->value)
            // Un abonnement sans échéance court indéfiniment (offre gratuite).
            ->where(function ($q) {
                $q->whereNull('user_subscriptions.end_date')
                    ->orWhere('user_subscriptions.end_date', '>=', now());
            })
            // Agrégat sans GROUP BY : toujours exactement une ligne, NULL quand
            // l'entreprise n'a aucun abonnement actif.
            ->selectRaw('COALESCE(MAX(subscription_plans.price), 0)');

        return $query
            ->orderByDesc($rangDuPlan)
            ->orderByDesc('job_offers.created_at')
            // Départage les offres créées dans la même seconde, pour que la
            // pagination reste stable d'une page à l'autre.
            ->orderByDesc('job_offers.id');
    }

    /** Le slug d'une offre dérive de son intitulé, pas d'un champ `name`. */
    protected function slugSource(): string
    {
        return 'title';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class);
    }

    /**
     * Questions de présélection configurées par l'entreprise, dans l'ordre
     * d'affichage du wizard de candidature.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(JobOfferQuestion::class)->orderBy('position');
    }
}
