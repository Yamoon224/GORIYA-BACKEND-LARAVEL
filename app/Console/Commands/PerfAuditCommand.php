<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\BenchmarkSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Mesure chaque point d'entrée de l'API sur le jeu de données de
 * BenchmarkSeeder : temps médian, nombre de requêtes SQL, temps SQL et
 * répétitions d'une même requête (signature d'un N+1).
 *
 *   DB_DATABASE=goriya_bench php artisan perf:audit --label=avant
 *
 * Les requêtes traversent le vrai noyau HTTP (middlewares, JWT, resources).
 * Les services externes sont neutralisés (HTTP simulé, mailer `array`) et les
 * écritures sont annulées : la commande se relance à l'identique.
 */
class PerfAuditCommand extends Command
{
    protected $signature = 'perf:audit {--label=run : Nom du relevé (fichier storage/app/perf/<label>.json)}
        {--runs=5 : Mesures par point d\'entrée, après un passage à blanc}
        {--only= : Ne mesurer que les URI contenant ce texte}
        {--dump : Afficher les requêtes SQL de chaque point d\x27entrée mesuré}';

    protected $description = 'Audit de performance des points d\'entrée de l\'API (temps, requêtes SQL, N+1)';

    /** URI sans intérêt pour la mesure : fichiers sur disque, redirections, outillage. */
    private const SKIP = [
        '/', 'up', 'docs', 'docs/asset/{asset}', 'api/documentation', 'api/oauth2-callback', 'storage/{path}',
        'partners/{partner}/unsubscribe', 'webhooks/paiementpro', 'subscriptions/checkout/status/{reference}',
        'subscriptions/checkout/verify/{transactionId}', 'candidatures/{candidatureId}/assessment/report',
        'employee-contracts/{id}/document', 'employee-contracts/{id}/draft', 'hr-documents/{id}/download',
        'enrollments/{id}/certificate', 'me/course-projects/{id}/file', 'admin/course-projects/{id}/file',
        'payslips/{id}/document', 'presentations/{id}/export-pptx', 'chat/search',
    ];

    /** Points d'entrée réservés au compte entreprise (les autres sont joués par le candidat). */
    private const ENTERPRISE_PREFIXES = [
        'employee', 'payroll', 'payslips', 'recruitment', 'hr-', 'api-clients', 'candidatures', 'job-offers/{jobOfferId}',
        'dashboard', 'analytics',
    ];

    /** `{id}` : quel échantillon selon le début de l'URI. */
    private const ID_BY_PREFIX = [
        'admin/audit-logs' => 'auditLog', 'admin/candidatures' => 'candidature', 'admin/companies' => 'company',
        'admin/courses' => 'course', 'admin/cv-analysis' => 'cvAnalysis', 'admin/influencer-payouts' => null,
        'admin/influencers' => 'influencer', 'admin/interview-simulation' => 'interviewSession', 'admin/job-offers' => 'jobOffer',
        'admin/mail-campaigns' => 'mailCampaign', 'admin/planning' => 'calendarEvent', 'admin/portfolios' => 'portfolio',
        'admin/potential-partners' => 'partner', 'admin/promo-campaigns' => 'promoCampaign', 'admin/promo-codes' => 'promoCode',
        'admin/scoring' => 'scoring', 'admin/students' => 'student', 'api-clients' => 'apiClient', 'calendar-events' => 'calendarEvent',
        'calls' => 'call', 'candidatures' => 'candidature', 'chat/threads' => 'thread', 'communities' => 'community',
        'companies' => 'company', 'courses' => 'course', 'cv-analysis' => 'cvAnalysis', 'employee-surveys' => 'survey',
        'employees' => 'employee', 'external/v1/candidatures' => 'candidature', 'interview-sessions' => 'interviewSession',
        'job-offers' => 'jobOffer', 'matching-results' => 'matching', 'payroll/runs' => 'payrollRun', 'payslips' => 'payslip',
        'pitches' => 'pitch', 'portfolios' => 'portfolio', 'posts' => 'post', 'presentations' => 'presentation',
        'recruitment/candidates' => 'candidature', 'research' => 'research', 'scoring-results' => 'scoring', 'users' => 'candidateUser',
    ];

    private const PARAMS = [
        'candidatureId' => 'candidature', 'jobOfferId' => 'jobOffer', 'jobId' => 'jobOffer', 'companyId' => 'company',
        'employeeId' => 'employee', 'conversationId' => 'conversation', 'courseId' => 'course', 'userId' => 'candidateUser',
        'featureKey' => 'featureKey', 'slug' => 'articleSlug', 'ref' => 'profileRef',
    ];

    /** Recherches et filtres : là où un index manquant coûte le plus. */
    private const EXTRA_GETS = [
        ['public', 'job-offers/paginate?page=1&limit=20&search=Développeur'],
        ['public', 'job-offers/paginate?page=3&limit=20&location=Abidjan&type=CDI'],
        ['public', 'companies/paginate?page=1&limit=20&search=Bench'],
        ['enterprise', 'candidatures/paginate?page=1&limit=50'],
        ['enterprise', 'candidatures/paginate?page=1&limit=20&status=EN_COURS'],
        ['enterprise', 'recruitment/candidates?search=Postulant+12'],
        ['enterprise', 'recruitment/candidates?stage=INTERVIEW'],
        ['enterprise', 'recruitment/interviews?status=SCHEDULED'],
        ['enterprise', 'calls'],
        ['enterprise', 'notifications'],
        ['enterprise', 'messages/conversations'],
        ['candidate', 'candidatures'],
        ['candidate', 'posts/feed?page=2'],
        ['admin', 'users/paginate?page=1&limit=20&search=Candidat+42'],
        ['admin', 'admin/potential-partners/paginate?page=1&limit=20&search=Société+12'],
        ['admin', 'admin/potential-partners/paginate?page=1&limit=20&sector=BTP&city=Abidjan'],
        ['admin', 'admin/audit-logs/paginate?page=1&limit=20&action=updated'],
        ['admin', 'admin/search?q=Développeur'],
        ['admin', 'admin/candidatures/paginate?page=1&limit=20&search=Postulant'],
    ];

    /** @var array<string, mixed> */
    private array $samples = [];

    /** @var array<string, array{user: ?User, token: ?string}> */
    private array $actors = [];

    /** @var list<array{sql: string, time: float}> */
    private array $queries = [];

    private int $mails = 0;

    private bool $terminating = false;

    private int $deferredMails = 0;

    public function handle(Kernel $kernel): int
    {
        if (! User::where('email', BenchmarkSeeder::CANDIDATE_EMAIL)->exists()) {
            $this->error('Jeu de données absent : lancez d\'abord `php artisan db:seed --class=BenchmarkSeeder` sur une base dédiée.');

            return self::FAILURE;
        }

        config(['mail.default' => 'array', 'app.debug' => false, 'services.lunion_meet.api_key' => 'bench']);
        Http::preventStrayRequests();
        Http::fake([
            '*' => fn () => Http::response(['id' => 'bench-room', 'slug' => 'bench-'.uniqid('', true), 'token' => 'bench', 'url' => 'https://meet.test', 'room' => 'bench', 'identity' => 'bench', 'expiresAt' => now()->addHour()->toIso8601String()], 200),
        ]);
        DB::listen(function (QueryExecuted $query) {
            $this->queries[] = ['sql' => $query->sql, 'time' => $query->time];
        });
        Event::listen(MessageSending::class, function () {
            $this->terminating ? $this->deferredMails++ : $this->mails++;
        });

        $this->prepareActors();
        $this->prepareSamples();

        $scenarios = array_merge($this->readScenarios(), $this->writeScenarios());
        if ($only = $this->option('only')) {
            $scenarios = array_values(array_filter($scenarios, fn (array $s) => str_contains($s['uri'], $only)));
        }

        $results = [];
        $skipped = [];
        $bar = $this->output->createProgressBar(count($scenarios));
        foreach ($scenarios as $scenario) {
            $result = $this->measure($kernel, $scenario);
            if ($result === null) {
                $skipped[] = $scenario['method'].' '.$scenario['route'];
            } else {
                $results[] = $result;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        usort($results, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);
        $this->table(
            ['Point d\'entrée', 'Acteur', 'HTTP', 'ms', 'SQL', 'ms SQL', 'Répét.', 'Ko'],
            array_map(fn (array $r) => [$r['method'].' '.$r['uri'], $r['actor'], $r['status'], $r['ms'], $r['queries'], $r['sqlMs'], $r['repeat'], round($r['bytes'] / 1024, 1)], array_slice($results, 0, 40)),
        );

        $path = storage_path('app/perf/'.$this->option('label').'.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'label' => $this->option('label'),
            'date' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(),
            'driver' => DB::connection()->getDriverName(),
            'runs' => (int) $this->option('runs'),
            'results' => $results,
            'skipped' => $skipped,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $ok = array_filter($results, fn (array $r) => $r['status'] < 400);
        $this->info(sprintf(
            '%d points d\'entrée mesurés (%d en erreur, %d non couverts) — total %.0f ms, %d requêtes SQL. Relevé : %s',
            count($results), count($results) - count($ok), count($skipped), array_sum(array_column($results, 'ms')), array_sum(array_column($results, 'queries')), $path,
        ));

        return self::SUCCESS;
    }

    private function prepareActors(): void
    {
        foreach (['admin' => BenchmarkSeeder::ADMIN_EMAIL, 'enterprise' => BenchmarkSeeder::ENTERPRISE_EMAIL, 'candidate' => BenchmarkSeeder::CANDIDATE_EMAIL] as $name => $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actors[$name] = ['user' => $user, 'token' => Auth::guard('api')->login($user)];
        }
        $this->actors['apikey'] = ['user' => null, 'token' => BenchmarkSeeder::API_TOKEN];
        $this->actors['public'] = ['user' => null, 'token' => null];
        $this->resetAuth();
    }

    private function prepareSamples(): void
    {
        $enterprise = $this->actors['enterprise']['user'];
        $candidate = $this->actors['candidate']['user'];
        $first = fn (string $table, array $where = [], string $column = 'id') => DB::table($table)->where($where)->orderBy($column)->value($column);
        $company = $enterprise->company_id;
        $candidature = DB::table('candidatures')->join('candidate_assessments', 'candidate_assessments.candidature_id', '=', 'candidatures.id')
            ->join('job_offers', 'job_offers.id', '=', 'candidatures.job_offer_id')->where('job_offers.company_id', $company)
            ->whereIn('candidatures.pipeline_stage', ['NEW', 'SCREENING'])->where('candidatures.user_id', '!=', $candidate->id)->orderBy('candidatures.id')->value('candidatures.id');

        $this->samples = [
            'company' => $company,
            'candidature' => $candidature,
            'jobOffer' => DB::table('candidatures')->where('id', $candidature)->value('job_offer_id'),
            'employee' => $first('employees', ['company_id' => $company]),
            'payrollRun' => $first('payroll_runs', ['company_id' => $company, 'status' => 'PAID']),
            'draftPayrollRun' => $first('payroll_runs', ['company_id' => $company, 'status' => 'DRAFT']),
            'payslip' => $first('payslips', ['company_id' => $company]),
            'survey' => $first('employee_surveys', ['company_id' => $company]),
            'apiClient' => $first('api_clients', ['company_id' => $company]),
            'call' => ['enterprise' => $first('call_sessions', ['host_id' => $enterprise->id]), '*' => $first('call_sessions', ['host_id' => $candidate->id])],
            'conversation' => ['enterprise' => $first('conversations', ['participant_one_id' => $enterprise->id]), '*' => $first('conversations', ['participant_one_id' => $candidate->id])],
            'candidateUser' => $candidate->id,
            'student' => $candidate->id,
            'thread' => $first('chat_threads', ['user_id' => $candidate->id]),
            'pitch' => $first('pitches', ['user_id' => $candidate->id]),
            'presentation' => $first('presentations', ['user_id' => $candidate->id]),
            'research' => $first('research_queries', ['user_id' => $candidate->id]),
            'portfolio' => $first('portfolios', ['user_id' => $candidate->id]),
            'post' => $first('posts', ['user_id' => $candidate->id]),
            'course' => DB::table('enrollments')->where('user_id', $candidate->id)->orderBy('id')->value('course_id'),
            'community' => $first('communities'),
            'auditLog' => $first('audit_logs'),
            'cvAnalysis' => $first('cv_analysis'),
            'influencer' => $first('influencers'),
            'interviewSession' => $first('interview_sessions'),
            'mailCampaign' => $first('mail_campaigns'),
            'calendarEvent' => $first('calendar_events'),
            'partner' => $first('potential_partners'),
            'promoCampaign' => $first('promo_campaigns'),
            'promoCode' => $first('promo_codes'),
            'scoring' => $first('scoring_results'),
            'matching' => $first('matching_results'),
            'articleSlug' => $first('articles', [], 'slug'),
            'profileRef' => 'bench-candidat',
            'featureKey' => 'cv_analysis',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function readScenarios(): array
    {
        $scenarios = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || in_array($uri, self::SKIP, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $actor = match (true) {
                in_array('role:ADMIN', $middleware, true), str_starts_with($uri, 'admin/') => 'admin',
                in_array('auth.apikey', $middleware, true) => 'apikey',
                ! in_array('auth:api', $middleware, true) => 'public',
                $this->startsWithAny($uri, self::ENTERPRISE_PREFIXES) => 'enterprise',
                default => 'candidate',
            };
            $query = str_ends_with($uri, 'paginate') ? '?page=1&limit=20' : '';
            $scenarios[] = ['method' => 'GET', 'route' => $uri, 'uri' => $uri.$query, 'actor' => $actor, 'body' => []];
        }
        foreach (self::EXTRA_GETS as [$actor, $uri]) {
            $scenarios[] = ['method' => 'GET', 'route' => $uri, 'uri' => $uri, 'actor' => $actor, 'body' => []];
        }

        return $scenarios;
    }

    /**
     * Écritures représentatives, annulées après chaque mesure.
     *
     * @return list<array<string, mixed>>
     */
    private function writeScenarios(): array
    {
        $in3days = now()->addDays(3)->setTime(10, 0)->toIso8601String();
        $write = fn (string $actor, string $method, string $uri, array $body = []) => ['method' => $method, 'route' => $uri, 'uri' => $uri, 'actor' => $actor, 'body' => $body, 'write' => true];

        return [
            $write('public', 'POST', 'auth/login', ['email' => BenchmarkSeeder::CANDIDATE_EMAIL, 'password' => 'password123']),
            $write('enterprise', 'POST', 'recruitment/candidates/{id}/interviews', ['type' => 'VIDEO', 'scheduledAt' => $in3days, 'durationMinutes' => 45]),
            $write('enterprise', 'POST', 'recruitment/candidates/{id}/interviews#onsite', ['type' => 'ONSITE', 'scheduledAt' => $in3days, 'location' => 'Plateau']),
            $write('enterprise', 'POST', 'recruitment/candidates/{id}/notes', ['body' => 'Très bon échange téléphonique.']),
            $write('enterprise', 'PATCH', 'candidatures/{id}', ['status' => 'EN_COURS']),
            $write('candidate', 'POST', 'calls', ['title' => 'Point projet', 'scheduledAt' => $in3days, 'invitees' => ['a@bench.test', 'b@bench.test', 'c@bench.test']]),
            $write('candidate', 'POST', 'calls/{id}/join'),
            $write('candidate', 'POST', 'messages/conversations/{conversationId}/messages', ['content' => 'Bonjour, merci pour votre retour.']),
            $write('candidate', 'POST', 'posts/{id}/like'),
            $write('candidate', 'POST', 'posts/{id}/comments', ['content' => 'Merci pour ce partage.']),
            $write('candidate', 'POST', 'posts', ['content' => 'Nouvelle publication de test.']),
            $write('candidate', 'PUT', 'notifications/read-all'),
            $write('candidate', 'POST', 'companies/{companyId}/follow'),
            $write('enterprise', 'POST', 'payroll/runs/{id}/recompute#draft'),
        ];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return ?array<string, mixed>
     */
    private function measure(Kernel $kernel, array $scenario): ?array
    {
        $actors = [$scenario['actor']];
        if (in_array($scenario['actor'], ['candidate', 'enterprise'], true) && empty($scenario['write'])) {
            $actors[] = $scenario['actor'] === 'candidate' ? 'enterprise' : 'candidate';
        }

        $best = null;
        foreach ($actors as $actor) {
            $uri = $this->resolveUri($scenario['uri'], $actor);
            if ($uri === null) {
                return null;
            }
            $samples = [];
            $runs = (int) $this->option('runs') + 1;
            for ($i = 0; $i < $runs; $i++) {
                $sample = $this->runOnce($kernel, $scenario, $uri, $actor);
                if ($i > 0 || $runs === 1) {
                    $samples[] = $sample;
                }
                if ($sample['status'] >= 400) {
                    break;
                }
            }
            $samples = $samples ?: [$sample];
            usort($samples, fn (array $a, array $b) => $a['ms'] <=> $b['ms']);
            $median = $samples[intdiv(count($samples), 2)];
            $best = ['method' => $scenario['method'], 'uri' => $scenario['uri'], 'actor' => $actor] + $median;
            if ($median['status'] < 400) {
                break;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function runOnce(Kernel $kernel, array $scenario, string $uri, string $actor): array
    {
        $this->resetAuth();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($token = $this->actors[$actor]['token']) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $request = Request::create('/'.strtok($uri, '#'), $scenario['method'], [], [], [], $server, $scenario['body'] ? json_encode($scenario['body']) : null);

        $write = ! empty($scenario['write']);
        if ($write) {
            DB::beginTransaction();
        }
        $this->queries = [];
        $this->mails = 0;
        $this->deferredMails = 0;
        Http::recorded();
        $httpBefore = count(Http::recorded());

        $error = null;
        $start = hrtime(true);
        try {
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $bytes = strlen((string) $response->getContent());
            if ($status >= 500) {
                $error = mb_substr((string) ($response->exception?->getMessage() ?? $response->getContent()), 0, 300);
            } elseif ($status >= 400) {
                $error = mb_substr((string) $response->getContent(), 0, 200);
            }
        } catch (Throwable $e) {
            $status = 500;
            $bytes = 0;
            $error = mb_substr($e->getMessage(), 0, 300);
            $response = null;
        }
        $ms = (hrtime(true) - $start) / 1e6;
        $queries = $this->queries;
        if ($this->option('dump')) {
            $this->newLine();
            $this->line("<info>{$scenario['method']} {$uri}</info> [{$actor}] {$status}");
            foreach ($queries as $query) {
                $this->line(sprintf('  %6.1f ms  %s', $query['time'], mb_substr($query['sql'], 0, 230)));
            }
        }

        // Travail différé après l'envoi de la réponse : hors du temps perçu.
        $this->terminating = true;
        try {
            if ($response) {
                $kernel->terminate($request, $response);
            }
        } catch (Throwable) {
        }
        $this->terminating = false;

        if ($write) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $counts = [];
        foreach ($queries as $query) {
            if (stripos($query['sql'], 'select') === 0) {
                $key = preg_replace(['/in \([?, ]+\)/i', '/\s+/'], ['in (?)', ' '], $query['sql']);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return [
            'status' => $status,
            'ms' => round($ms, 1),
            'queries' => count($queries),
            'sqlMs' => round(array_sum(array_column($queries, 'time')), 1),
            'repeat' => $counts ? reset($counts) : 0,
            'repeatSql' => $counts && reset($counts) > 2 ? mb_substr((string) array_key_first($counts), 0, 160) : null,
            'slowSql' => $queries ? mb_substr(collect($queries)->sortByDesc('time')->first()['sql'], 0, 200) : null,
            'slowSqlMs' => $queries ? round(max(array_column($queries, 'time')), 1) : 0,
            'bytes' => $bytes,
            'mails' => $this->mails,
            'deferredMails' => $this->deferredMails,
            'http' => count(Http::recorded()) - $httpBefore,
            'error' => $error,
        ];
    }

    private function resolveUri(string $uri, string $actor): ?string
    {
        $unresolved = false;
        $resolved = preg_replace_callback('/\{(\w+)\}/', function (array $m) use ($uri, $actor, &$unresolved) {
            $key = self::PARAMS[$m[1]] ?? null;
            if ($m[1] === 'id') {
                if (str_contains($uri, '#draft')) {
                    $key = 'draftPayrollRun';
                } else {
                    foreach (self::ID_BY_PREFIX as $prefix => $sample) {
                        if (str_starts_with($uri, $prefix)) {
                            $key = $sample;
                            break;
                        }
                    }
                }
            }
            $value = $key ? ($this->samples[$key] ?? null) : null;
            if (is_array($value)) {
                $value = $value[$actor] ?? $value['*'];
            }
            if (! $value) {
                $unresolved = true;
            }

            return (string) $value;
        }, $uri);

        return $unresolved ? null : $resolved;
    }

    /** JWT et garde gardent le dernier utilisateur en mémoire : on repart à neuf. */
    private function resetAuth(): void
    {
        Auth::forgetGuards();
        app('tymon.jwt')->unsetToken();
        app()->forgetScopedInstances();
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $uri, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
