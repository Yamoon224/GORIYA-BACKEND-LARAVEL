<?php

namespace Tests\Feature;

use App\Models\Candidature;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\JobOffer;
use App\Models\JobOfferQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Garde-fou anti-N+1 : le nombre de requêtes SQL d'une liste ne doit pas
 * dépendre du nombre de lignes affichées. Chaque test mesure la même page
 * avec peu puis beaucoup de lignes et exige le même compte.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $recruiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Goriya Test SARL', 'sector' => 'Technologie', 'status' => 'ACTIVE', 'partnership_date' => '2026-01-01']);
        $this->recruiter = $this->user('ENTREPRISE', $this->company->id);
    }

    private function user(string $role = 'USER', ?string $companyId = null): User
    {
        return User::create([
            'name' => 'Utilisateur '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@example.ci',
            'password' => 'motdepasse-solide',
            'role' => $role,
            'status' => 'ACTIVE',
            'company_id' => $companyId,
        ]);
    }

    private function offer(): JobOffer
    {
        $offer = JobOffer::create(['title' => 'Poste '.Str::random(5), 'type' => 'CDI', 'company_id' => $this->company->id, 'status' => 'ACTIVE']);
        JobOfferQuestion::create(['job_offer_id' => $offer->id, 'label' => 'Pourquoi ce poste ?', 'type' => 'TEXT', 'required' => true, 'position' => 0]);

        return $offer;
    }

    private function application(JobOffer $offer): Candidature
    {
        $candidate = $this->user();

        return Candidature::create([
            'candidate_name' => $candidate->name,
            'candidate_email' => $candidate->email,
            'status' => 'EN_ATTENTE',
            'score' => 50,
            'applied_date' => now(),
            'user_id' => $candidate->id,
            'job_offer_id' => $offer->id,
        ]);
    }

    /** Nombre de requêtes SQL d'un GET, hors authentification déjà résolue. */
    private function queriesFor(string $uri, ?User $as = null): int
    {
        $this->app['auth']->forgetGuards();
        $request = $as ? $this->actingAs($as, 'api') : $this;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $request->getJson($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_public_job_list_does_not_query_once_per_offer(): void
    {
        $this->offer();
        $this->offer();
        $few = $this->queriesFor('/job-offers/paginate?page=1&limit=50');

        foreach (range(1, 8) as $_) {
            $this->offer();
        }

        $this->assertSame($few, $this->queriesFor('/job-offers/paginate?page=1&limit=50'));
        $this->assertSame($this->queriesFor('/job-offers'), $this->queriesFor('/job-offers'));
        $this->getJson('/job-offers/paginate?page=1&limit=50')->assertJsonCount(10, 'data')->assertJsonPath('data.0.questions.0.label', 'Pourquoi ce poste ?');
    }

    public function test_the_application_lists_do_not_query_once_per_application(): void
    {
        $offer = $this->offer();
        $this->application($offer);
        $this->application($offer);
        $few = [
            $this->queriesFor('/candidatures', $this->recruiter),
            $this->queriesFor('/candidatures/paginate?page=1&limit=50', $this->recruiter),
            $this->queriesFor('/recruitment/candidates', $this->recruiter),
            $this->queriesFor('/dashboard/stats', $this->recruiter),
        ];

        foreach (range(1, 8) as $_) {
            $this->application($this->offer());
        }

        $this->assertSame($few, [
            $this->queriesFor('/candidatures', $this->recruiter),
            $this->queriesFor('/candidatures/paginate?page=1&limit=50', $this->recruiter),
            $this->queriesFor('/recruitment/candidates', $this->recruiter),
            $this->queriesFor('/dashboard/stats', $this->recruiter),
        ]);
        $this->actingAs($this->recruiter, 'api')->getJson('/candidatures')->assertJsonCount(10);
    }

    public function test_the_inbox_does_not_query_once_per_conversation(): void
    {
        $open = function (): void {
            $other = $this->user();
            $conversation = Conversation::create(['participant_one_id' => $this->recruiter->id, 'participant_two_id' => $other->id, 'last_message_at' => now()]);
            $conversation->messages()->create(['sender_id' => $other->id, 'content' => 'Bonjour']);
            $this->travel(1)->minutes();
            $conversation->messages()->create(['sender_id' => $other->id, 'content' => 'Dernier message']);
        };

        $open();
        $open();
        $few = $this->queriesFor('/messages/conversations', $this->recruiter);

        foreach (range(1, 8) as $_) {
            $open();
        }

        $this->assertSame($few, $this->queriesFor('/messages/conversations', $this->recruiter));
        $this->actingAs($this->recruiter, 'api')
            ->getJson('/messages/conversations')
            ->assertJsonCount(10)
            ->assertJsonPath('0.unreadCount', 2)
            ->assertJsonPath('0.lastMessage', 'Dernier message');
    }
}
