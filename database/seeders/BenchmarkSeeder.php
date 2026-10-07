<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Jeu de données volumétrique pour l'audit de performance (`php artisan
 * perf:audit`). À lancer sur une base dédiée, après DatabaseSeeder :
 *
 *   DB_DATABASE=goriya_bench php artisan migrate:fresh --seed
 *   DB_DATABASE=goriya_bench php artisan db:seed --class=BenchmarkSeeder
 *
 * Trois comptes « héros » concentrent le volume d'une entreprise et d'un
 * candidat très actifs — c'est sur eux que les N+1 et les index manquants
 * se voient. Mot de passe : password123.
 */
class BenchmarkSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'bench-admin@goriya.test';

    public const ENTERPRISE_EMAIL = 'bench-entreprise@goriya.test';

    public const CANDIDATE_EMAIL = 'bench-candidat@goriya.test';

    public const API_TOKEN = 'bench-api-token-0000000000000000000000000000';

    private Carbon $now;

    public function run(): void
    {
        mt_srand(20261007);
        $this->now = Carbon::now()->startOfMinute();
        $password = Hash::make('password123');

        // --- Comptes héros -------------------------------------------------
        $companyId = $this->uuid();
        $this->ins('companies', [[
            'id' => $companyId, 'name' => 'Bench Corp', 'slug' => 'bench-corp', 'sector' => 'Technologie',
            'about' => 'Entreprise de référence pour les mesures de performance.', 'partnership_date' => $this->now->toDateString(),
            'company_size' => '201-500', 'country' => "Côte d'Ivoire", 'headquarters' => 'Abidjan', 'location' => 'Abidjan',
            'email' => 'contact@bench-corp.test', 'status' => 'ACTIVE', 'social_links' => '[]',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]]);

        $adminId = $this->uuid();
        $enterpriseId = $this->uuid();
        $candidateId = $this->uuid();
        $user = fn (string $id, string $name, string $email, string $role, ?string $company = null) => [
            'id' => $id, 'name' => $name, 'email' => $email, 'password' => $password, 'role' => $role, 'status' => 'ACTIVE',
            'locale' => 'fr', 'email_verified_at' => $this->now, 'registration_date' => $this->now, 'company_id' => $company,
            'title' => 'Développeur full-stack', 'location' => 'Abidjan', 'bio' => 'Profil de test.',
            'created_at' => $this->now, 'updated_at' => $this->now,
        ];
        $this->ins('users', [
            $user($adminId, 'Bench Admin', self::ADMIN_EMAIL, 'ADMIN'),
            $user($enterpriseId, 'Bench Recruteur', self::ENTERPRISE_EMAIL, 'ENTREPRISE', $companyId),
            $user($candidateId, 'Bench Candidat', self::CANDIDATE_EMAIL, 'USER'),
        ]);

        // --- Population de fond -------------------------------------------
        $userIds = [];
        $rows = [];
        for ($i = 0; $i < 6000; $i++) {
            $id = $this->uuid();
            $userIds[] = $id;
            $created = $this->past(365);
            $rows[] = [
                'id' => $id, 'name' => "Candidat {$i}", 'email' => "candidat{$i}@bench.test", 'password' => $password,
                'role' => 'USER', 'status' => $i % 25 === 0 ? 'INACTIVE' : 'ACTIVE', 'locale' => 'fr', 'email_verified_at' => $created,
                'registration_date' => $created, 'company_id' => null, 'title' => $this->pick(['Comptable', 'Développeur', 'Commercial', 'Juriste', 'Chef de projet']),
                'location' => $this->pick(['Abidjan', 'Bouaké', 'Yamoussoukro', 'San-Pédro']), 'bio' => null,
                'created_at' => $created, 'updated_at' => $created,
            ];
        }
        $this->ins('users', $rows);

        $companyIds = DB::table('companies')->where('id', '!=', $companyId)->pluck('id')->all();
        $jobIds = DB::table('job_offers')->pluck('id')->all();
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $id = $this->uuid();
            $jobIds[] = $id;
            $created = $this->past(120);
            $rows[] = $this->job($id, "Poste {$i} ".$this->pick(['Comptable', 'Développeur PHP', 'Commercial terrain', 'Juriste', 'Chef de projet']), $this->pick($companyIds), $created, "poste-bench-{$i}");
        }
        $benchJobIds = [];
        for ($i = 0; $i < 40; $i++) {
            $id = $this->uuid();
            $benchJobIds[] = $id;
            $rows[] = $this->job($id, "Bench — Offre {$i}", $companyId, $this->past(90), "bench-offre-{$i}", $i < 34 ? 'ACTIVE' : ($i < 38 ? 'CLOSED' : 'DRAFT'));
        }
        $this->ins('job_offers', $rows);

        $rows = [];
        foreach ($benchJobIds as $jobId) {
            foreach (['Combien d\'années d\'expérience ?' => 'NUMBER', 'Pourquoi ce poste ?' => 'TEXTAREA'] as $label => $type) {
                $rows[] = ['id' => $this->uuid(), 'job_offer_id' => $jobId, 'label' => $label, 'type' => $type, 'options' => null, 'required' => 1, 'position' => count($rows) % 2, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('job_offer_questions', $rows);
        $questionsByJob = collect($rows)->groupBy('job_offer_id');

        // --- Candidatures --------------------------------------------------
        $statuses = ['EN_ATTENTE', 'EN_ATTENTE', 'EN_COURS', 'APPROUVEE', 'REJETEE'];
        $rows = [];
        for ($i = 0; $i < 25000; $i++) {
            $rows[] = $this->candidature($this->uuid(), $this->pick($userIds), "Candidat {$i}", "c{$i}@bench.test", $this->pick($jobIds), $this->pick($statuses), null);
        }
        $this->ins('candidatures', $rows);

        // Entreprise héros : 1 500 candidatures avec CV, réponses, étapes et notes IA.
        $benchCandidatures = [];
        $resumes = [];
        $answers = [];
        $rows = [];
        $stages = ['NEW', 'NEW', 'SCREENING', 'SCREENING', 'INTERVIEW', 'INTERVIEW', 'OFFER', 'REJECTED'];
        $stageStatus = ['NEW' => 'EN_ATTENTE', 'SCREENING' => 'EN_COURS', 'INTERVIEW' => 'EN_COURS', 'OFFER' => 'APPROUVEE', 'REJECTED' => 'REJETEE'];
        for ($i = 0; $i < 1500; $i++) {
            $id = $this->uuid();
            $applicant = $i < 60 ? $candidateId : $userIds[$i];
            $jobId = $i < 60 ? $benchJobIds[$i % 34] : $this->pick(array_slice($benchJobIds, 0, 38));
            $stage = $this->pick($stages);
            $resumeId = $this->uuid();
            $resumes[] = ['id' => $resumeId, 'user_id' => $applicant, 'name' => "CV {$i}.pdf", 'source' => 'UPLOAD', 'path' => "resumes/bench-{$i}.pdf", 'mime_type' => 'application/pdf', 'size' => 120000, 'is_default' => $i >= 60 || $i === 0 ? 1 : 0, 'created_at' => $this->now, 'updated_at' => $this->now];
            $row = $this->candidature($id, $applicant, $i < 60 ? 'Bench Candidat' : "Postulant {$i}", $i < 60 ? self::CANDIDATE_EMAIL : "postulant{$i}@bench.test", $jobId, $stageStatus[$stage], $resumeId);
            $row['pipeline_stage'] = $stage;
            $row['stage_changed_at'] = $row['applied_date'];
            $rows[] = $row;
            $benchCandidatures[] = ['id' => $id, 'user_id' => $applicant, 'stage' => $stage, 'job_offer_id' => $jobId];
            foreach ($questionsByJob[$jobId] ?? [] as $position => $question) {
                $answers[] = ['id' => $this->uuid(), 'candidature_id' => $id, 'question_id' => $question['id'], 'question_label' => $question['label'], 'question_type' => $question['type'], 'value' => json_encode($question['type'] === 'NUMBER' ? 4 : 'Motivé par le poste.'), 'position' => $position, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('user_resumes', $resumes);
        $this->ins('candidatures', $rows);
        $this->ins('candidature_answers', $answers);

        $rows = [];
        foreach (array_slice($benchCandidatures, 0, 700) as $c) {
            $rows[] = [
                'id' => $this->uuid(), 'candidature_id' => $c['id'], 'technical_score' => mt_rand(40, 95), 'soft_skills_score' => mt_rand(40, 95),
                'cultural_fit_score' => mt_rand(40, 95), 'overall_score' => mt_rand(40, 95),
                'skills_test' => json_encode([['question' => 'Décrivez un projet récent.', 'type' => 'technique'], ['question' => 'Comment gérez-vous un conflit ?', 'type' => 'comportemental']]),
                'soft_skills_feedback' => 'Bon relationnel, communication claire.', 'status' => 'COMPLETED', 'created_at' => $this->now, 'updated_at' => $this->now,
            ];
        }
        $this->ins('candidate_assessments', $rows);

        $notes = [];
        $events = [];
        foreach ($benchCandidatures as $i => $c) {
            if ($i % 2 === 0) {
                $notes[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'candidature_id' => $c['id'], 'body' => 'Profil intéressant, à rappeler.', 'created_by' => $enterpriseId, 'created_at' => $this->past(30), 'updated_at' => $this->now];
            }
            if ($c['stage'] !== 'NEW') {
                $events[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'candidature_id' => $c['id'], 'from_stage' => 'NEW', 'to_stage' => $c['stage'], 'comment' => null, 'created_by' => $enterpriseId, 'created_at' => $this->past(30), 'updated_at' => $this->now];
            }
        }
        $this->ins('recruitment_notes', $notes);
        $this->ins('recruitment_stage_events', $events);

        // --- GORIYA Meet et entretiens --------------------------------------
        // Un tiers des entretiens « planifiés » sont déjà passés : c'est le cas
        // que la clôture automatique doit rattraper.
        $calls = [];
        $guests = [];
        $interviews = [];
        $interviewees = array_values(array_filter($benchCandidatures, fn ($c) => in_array($c['stage'], ['INTERVIEW', 'OFFER'], true)));
        foreach (array_slice($interviewees, 0, 400) as $i => $c) {
            $type = ['VIDEO', 'VIDEO', 'PHONE', 'ONSITE'][$i % 4];
            $when = $i % 3 === 0 ? $this->now->copy()->subDays(mt_rand(1, 60))->setTime(mt_rand(8, 17), 0) : $this->now->copy()->addDays(mt_rand(1, 30))->setTime(mt_rand(8, 17), 0);
            $status = $i % 3 === 0 ? ($i % 6 === 0 ? 'SCHEDULED' : 'COMPLETED') : 'SCHEDULED';
            $callId = null;
            if ($type === 'VIDEO') {
                $callId = $this->uuid();
                $calls[] = ['id' => $callId, 'host_id' => $enterpriseId, 'title' => "Entretien Bench — Postulant {$i}", 'room_slug' => 'bench-room-'.$i.'-'.Str::random(6), 'room_ref' => null, 'scheduled_at' => $when, 'invitees' => null, 'description' => null, 'status' => $status === 'COMPLETED' ? 'ENDED' : ($i % 12 === 0 ? 'ACTIVE' : 'SCHEDULED'), 'ended_at' => $status === 'COMPLETED' ? $when : null, 'created_at' => $this->past(60), 'updated_at' => $this->now];
                $guests[$callId.$c['user_id']] = ['call_session_id' => $callId, 'user_id' => $c['user_id'], 'created_at' => $this->now, 'updated_at' => $this->now];
            }
            $interviews[] = [
                'id' => $this->uuid(), 'company_id' => $companyId, 'candidature_id' => $c['id'], 'type' => $type, 'scheduled_at' => $when, 'duration_minutes' => 45,
                'location' => $type === 'ONSITE' ? 'Plateau, Abidjan' : null, 'interviewers' => 'Bench Recruteur', 'description' => null, 'status' => $status,
                'rating' => $status === 'COMPLETED' ? mt_rand(2, 5) : null, 'recommendation' => $status === 'COMPLETED' ? 'MAYBE' : null, 'feedback' => null,
                'candidate_notified_at' => $this->now, 'created_by' => $enterpriseId, 'outcome_by' => $status === 'COMPLETED' ? $enterpriseId : null,
                'outcome_at' => $status === 'COMPLETED' ? $when : null, 'call_session_id' => $callId, 'created_at' => $this->past(60), 'updated_at' => $this->now,
            ];
        }
        // Appels libres du candidat héros, dont la moitié dépassés mais restés « en cours ».
        for ($i = 0; $i < 60; $i++) {
            $callId = $this->uuid();
            $when = $i % 2 === 0 ? $this->now->copy()->subDays(mt_rand(1, 40)) : $this->now->copy()->addDays(mt_rand(1, 20));
            $calls[] = ['id' => $callId, 'host_id' => $candidateId, 'title' => "Appel {$i}", 'room_slug' => 'bench-free-'.$i.'-'.Str::random(6), 'room_ref' => null, 'scheduled_at' => $i % 5 === 0 ? null : $when, 'invitees' => json_encode(["invite{$i}@bench.test"]), 'description' => 'Point hebdomadaire', 'status' => $i % 4 === 0 ? 'ACTIVE' : 'SCHEDULED', 'ended_at' => null, 'created_at' => $when->copy()->subDays(2), 'updated_at' => $this->now];
            $guests[$callId.$userIds[$i]] = ['call_session_id' => $callId, 'user_id' => $userIds[$i], 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('call_sessions', $calls);
        $this->ins('call_session_guests', array_values($guests));
        $this->ins('recruitment_interviews', $interviews);

        // --- Services RH -----------------------------------------------------
        $employees = [];
        $employeeIds = [];
        for ($i = 0; $i < 150; $i++) {
            $id = $this->uuid();
            $employeeIds[] = $id;
            $employees[] = [
                'id' => $id, 'company_id' => $companyId, 'manager_id' => $i > 5 ? $employeeIds[$i % 5] : null, 'candidature_id' => null,
                'user_id' => $i === 0 ? $candidateId : $userIds[2000 + $i], 'matricule' => sprintf('BC-%04d', $i), 'first_name' => "Prénom{$i}", 'last_name' => "Nom{$i}",
                'gender' => $i % 2 ? 'M' : 'F', 'birth_date' => '1990-05-12', 'email' => "employe{$i}@bench-corp.test", 'phone' => '+2250700000000', 'address' => 'Cocody',
                'job_title' => $this->pick(['Comptable', 'Développeur', 'Commercial', 'RH', 'Support']), 'department' => $this->pick(['Finance', 'Tech', 'Ventes', 'RH']),
                'contract_type' => $this->pick(['CDI', 'CDI', 'CDD', 'STAGE']), 'hire_date' => $this->now->copy()->subDays(mt_rand(60, 1500))->toDateString(), 'contract_end_date' => null,
                'salary' => mt_rand(250, 1500) * 1000, 'annual_leave_days' => 26, 'status' => $i % 20 === 0 ? 'PROBATION' : 'ACTIVE', 'notes' => null,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ];
        }
        $this->ins('employees', $employees);

        $contracts = [];
        $leaves = [];
        $requests = [];
        $documents = [];
        foreach ($employees as $i => $e) {
            $contracts[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'employee_id' => $e['id'], 'parent_id' => null, 'reference' => sprintf('CT-%05d', $i), 'kind' => 'INITIAL', 'type' => $e['contract_type'], 'job_title' => $e['job_title'], 'start_date' => $e['hire_date'], 'end_date' => null, 'salary' => $e['salary'], 'weekly_hours' => 40, 'status' => 'ACTIVE', 'signed_at' => $e['hire_date'], 'created_by' => $enterpriseId, 'created_at' => $this->now, 'updated_at' => $this->now];
            for ($k = 0; $k < 6; $k++) {
                $start = $this->now->copy()->subDays(mt_rand(-60, 300));
                $days = mt_rand(1, 10);
                $leaves[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'employee_id' => $e['id'], 'type' => $this->pick(['PAID', 'PAID', 'SICK', 'UNPAID', 'FAMILY_EVENT']), 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days)->toDateString(), 'days' => $days, 'reason' => 'Repos', 'status' => $this->pick(['PENDING', 'APPROVED', 'APPROVED', 'REJECTED']), 'created_by' => $e['user_id'], 'decided_by' => $enterpriseId, 'decided_at' => $this->now, 'created_at' => $this->past(200), 'updated_at' => $this->now];
            }
            for ($k = 0; $k < 3; $k++) {
                $requests[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'employee_id' => $e['id'], 'type' => $this->pick(['WORK_CERTIFICATE', 'SALARY_CERTIFICATE', 'SALARY_ADVANCE', 'TRAINING', 'OTHER']), 'subject' => "Demande {$k}", 'description' => 'Merci de traiter cette demande.', 'amount' => null, 'status' => $this->pick(['PENDING', 'IN_PROGRESS', 'APPROVED', 'REJECTED']), 'created_by' => $e['user_id'], 'decided_by' => null, 'decided_at' => null, 'created_at' => $this->past(200), 'updated_at' => $this->now];
                $documents[] = ['id' => $this->uuid(), 'company_id' => $companyId, 'employee_id' => $e['id'], 'category' => $this->pick(['IDENTITY', 'CONTRACT', 'CERTIFICATE', 'PAYROLL']), 'title' => "Document {$k}", 'description' => null, 'file_path' => "hr/bench-{$i}-{$k}.pdf", 'file_name' => "document-{$k}.pdf", 'mime_type' => 'application/pdf', 'size' => 50000, 'issued_at' => $this->now->toDateString(), 'expires_at' => null, 'source' => 'UPLOAD', 'template' => null, 'hr_request_id' => null, 'uploaded_by' => $enterpriseId, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('employee_contracts', $contracts);
        $this->ins('employee_leaves', $leaves);
        $this->ins('hr_requests', $requests);
        $this->ins('employee_documents', $documents);

        // Paramètres de paie : valeurs par défaut (aucune ligne payroll_settings).
        $runs = [];
        $payslips = [];
        for ($m = 0; $m < 12; $m++) {
            $period = $this->now->copy()->startOfMonth()->subMonths($m + 1);
            $runId = $this->uuid();
            $runs[] = ['id' => $runId, 'company_id' => $companyId, 'year' => $period->year, 'month' => $period->month, 'status' => $m === 0 ? 'DRAFT' : 'PAID', 'employees_count' => 150, 'total_gross' => 90000000, 'total_employee_contributions' => 5000000, 'total_employer_contributions' => 7000000, 'total_tax' => 9000000, 'total_net' => 76000000, 'total_employer_cost' => 97000000, 'created_by' => $enterpriseId, 'created_at' => $period, 'updated_at' => $period];
            foreach ($employees as $e) {
                $payslips[] = [
                    'id' => $this->uuid(), 'company_id' => $companyId, 'payroll_run_id' => $runId, 'employee_id' => $e['id'],
                    'employee_snapshot' => json_encode(['id' => $e['id'], 'name' => $e['first_name'].' '.$e['last_name'], 'matricule' => $e['matricule'], 'jobTitle' => $e['job_title'], 'department' => $e['department']]),
                    'base_salary' => $e['salary'], 'business_days' => 22, 'worked_days' => 22, 'unpaid_leave_days' => 0, 'bonuses' => '[]', 'deductions' => '[]', 'advance_ids' => '[]',
                    'lines' => json_encode([['label' => 'Salaire de base', 'amount' => $e['salary']], ['label' => 'CNPS', 'amount' => -(int) ($e['salary'] * 0.063)]]), 'warnings' => '[]',
                    'gross' => $e['salary'], 'employee_contributions' => (int) ($e['salary'] * 0.063), 'employer_contributions' => (int) ($e['salary'] * 0.077), 'taxable' => $e['salary'], 'tax' => (int) ($e['salary'] * 0.1), 'advances' => 0, 'other_deductions' => 0,
                    'net' => (int) ($e['salary'] * 0.837), 'employer_cost' => (int) ($e['salary'] * 1.077), 'created_at' => $period, 'updated_at' => $period,
                ];
            }
        }
        $this->ins('payroll_runs', $runs);
        $this->ins('payslips', $payslips);

        $surveys = [];
        $responses = [];
        for ($s = 0; $s < 8; $s++) {
            $surveyId = $this->uuid();
            $surveys[] = ['id' => $surveyId, 'company_id' => $companyId, 'created_by' => $enterpriseId, 'title' => "Évaluation {$s}", 'description' => 'Climat social', 'questions' => json_encode([['id' => 'q1', 'question' => 'Satisfaction globale', 'type' => 'RATING'], ['id' => 'q2', 'question' => 'Suggestions', 'type' => 'TEXT']]), 'status' => $s < 5 ? 'ACTIVE' : 'CLOSED', 'due_date' => $this->now->copy()->addDays(20)->toDateString(), 'department' => null, 'created_at' => $this->now, 'updated_at' => $this->now];
            foreach (array_slice($employees, 0, 100) as $e) {
                $responses[] = ['id' => $this->uuid(), 'survey_id' => $surveyId, 'user_id' => $e['user_id'], 'answers' => json_encode(['q1' => mt_rand(1, 5), 'q2' => 'RAS']), 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('employee_surveys', $surveys);
        $this->ins('survey_responses', $responses);

        // --- Abonnements -----------------------------------------------------
        $plans = DB::table('subscription_plans')->orderByDesc('price')->get();
        $bestUserPlan = $plans->firstWhere('user_type', 'USER');
        $bestCompanyPlan = $plans->firstWhere('user_type', 'ENTREPRISE');
        $subs = [];
        $txs = [];
        $sub = fn (string $userId, ?string $planId, string $status = 'ACTIVE') => ['id' => $this->uuid(), 'user_id' => $userId, 'plan_id' => $planId, 'status' => $status, 'start_date' => $this->past(25), 'end_date' => $this->now->copy()->addDays(30), 'period_months' => 1, 'auto_renew' => 0, 'created_at' => $this->past(25), 'updated_at' => $this->now];
        $subs[] = $sub($candidateId, $bestUserPlan->id);
        $subs[] = $sub($enterpriseId, $bestCompanyPlan->id);
        foreach (array_slice($userIds, 0, 2500) as $i => $userId) {
            $plan = $plans[$i % $plans->count()];
            $subs[] = $sub($userId, $plan->id, $i % 4 === 0 ? 'EXPIRED' : 'ACTIVE');
            $txs[] = ['id' => $this->uuid(), 'user_id' => $userId, 'plan_id' => $plan->id, 'promo_code_id' => null, 'purpose' => 'SUBSCRIPTION', 'feature_key' => null, 'gateway' => 'paiementpro', 'gateway_transaction_id' => 'bench-'.$i, 'amount' => $plan->price, 'discount_amount' => null, 'period_months' => 1, 'currency' => 'XOF', 'status' => $i % 6 === 0 ? 'FAILED' : 'SUCCESS', 'raw_payload' => null, 'created_at' => $this->past(300), 'updated_at' => $this->now];
        }
        $this->ins('user_subscriptions', $subs);
        $this->ins('transactions', $txs);

        // --- Notifications, messagerie, réseau ---------------------------------
        $rows = [];
        foreach ([$candidateId, $enterpriseId] as $hero) {
            for ($i = 0; $i < 400; $i++) {
                $rows[] = $this->notification($hero, $i);
            }
        }
        for ($i = 0; $i < 40000; $i++) {
            $rows[] = $this->notification($this->pick($userIds), $i);
        }
        $this->ins('notifications', $rows);

        $conversations = [];
        $messages = [];
        $conversation = function (string $one, string $two, ?string $candidatureId, int $count) use (&$conversations, &$messages) {
            $id = $this->uuid();
            $last = $this->past(20);
            $conversations[] = ['id' => $id, 'candidature_id' => $candidatureId, 'participant_one_id' => $one, 'participant_two_id' => $two, 'last_message_at' => $last, 'starred_by' => null, 'deleted_by' => null, 'created_at' => $last, 'updated_at' => $last];
            for ($m = 0; $m < $count; $m++) {
                $at = $last->copy()->subMinutes(($count - $m) * 7);
                $messages[] = ['id' => $this->uuid(), 'conversation_id' => $id, 'sender_id' => $m % 2 ? $one : $two, 'content' => "Message {$m} : bonjour, voici la suite de notre échange.", 'read_at' => $m < $count - 4 ? $at : null, 'created_at' => $at, 'updated_at' => $at];
            }
        };
        // Recruteur ↔ 120 candidats (dont le candidat héros), 40 messages chacun.
        foreach (array_slice($benchCandidatures, 59, 120) as $c) {
            $conversation($enterpriseId, $c['user_id'], $c['id'], 40);
        }
        // Candidat héros ↔ 40 membres.
        for ($i = 0; $i < 40; $i++) {
            $conversation($candidateId, $userIds[3000 + $i], null, 40);
        }
        for ($i = 0; $i < 1500; $i++) {
            $conversation($userIds[$i], $userIds[5999 - $i], null, 12);
        }
        $this->ins('conversations', $conversations);
        $this->ins('messages', $messages);

        $pairs = [];
        for ($i = 0; $i < 300; $i++) {
            $pairs[$candidateId.$userIds[$i]] = [$candidateId, $userIds[$i]];
            $pairs[$userIds[$i + 150].$candidateId] = [$userIds[$i + 150], $candidateId];
        }
        while (count($pairs) < 30000) {
            $a = $this->pick($userIds);
            $b = $this->pick($userIds);
            if ($a !== $b) {
                $pairs[$a.$b] = [$a, $b];
            }
        }
        $this->ins('connections', array_map(fn ($p) => ['id' => $this->uuid(), 'follower_id' => $p[0], 'following_id' => $p[1], 'created_at' => $this->now, 'updated_at' => $this->now], array_values($pairs)));

        $follows = [];
        foreach (array_slice($companyIds, 0, 40) as $cid) {
            $follows[$candidateId.$cid] = [$candidateId, $cid];
        }
        while (count($follows) < 10000) {
            $u = $this->pick($userIds);
            $c = $this->pick($companyIds);
            $follows[$u.$c] = [$u, $c];
        }
        foreach (array_slice($userIds, 0, 500) as $u) {
            $follows[$u.$companyId] = [$u, $companyId];
        }
        $this->ins('company_follows', array_map(fn ($p) => ['id' => $this->uuid(), 'user_id' => $p[0], 'company_id' => $p[1], 'created_at' => $this->now, 'updated_at' => $this->now], array_values($follows)));

        $saved = [];
        foreach (array_slice($jobIds, 0, 60) as $j) {
            $saved[$candidateId.$j] = [$candidateId, $j];
        }
        while (count($saved) < 8000) {
            $u = $this->pick($userIds);
            $j = $this->pick($jobIds);
            $saved[$u.$j] = [$u, $j];
        }
        $this->ins('saved_jobs', array_map(fn ($p) => ['id' => $this->uuid(), 'user_id' => $p[0], 'job_offer_id' => $p[1], 'created_at' => $this->now, 'updated_at' => $this->now], array_values($saved)));

        // --- Communautés et publications ---------------------------------------
        $communityIds = [];
        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $id = $this->uuid();
            $communityIds[] = $id;
            $rows[] = ['id' => $id, 'name' => "Communauté {$i}", 'slug' => "communaute-bench-{$i}", 'description' => 'Échanges entre professionnels.', 'type' => ['SECTOR', 'COUNTRY', 'EXPERTISE'][$i % 3], 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('communities', $rows);
        $members = [];
        foreach (array_slice($communityIds, 0, 8) as $c) {
            $members[$c.$candidateId] = [$c, $candidateId];
        }
        while (count($members) < 12000) {
            $c = $this->pick($communityIds);
            $u = $this->pick($userIds);
            $members[$c.$u] = [$c, $u];
        }
        $this->ins('community_memberships', array_map(fn ($p) => ['id' => $this->uuid(), 'community_id' => $p[0], 'user_id' => $p[1], 'created_at' => $this->now, 'updated_at' => $this->now], array_values($members)));

        $postIds = [];
        $posts = [];
        for ($i = 0; $i < 8000; $i++) {
            $id = $this->uuid();
            $at = $this->past(90);
            // Les 300 premiers membres sont suivis par le candidat héros : son fil est dense.
            $author = $i % 3 === 0 ? $userIds[$i % 300] : $this->pick($userIds);
            $posts[] = ['id' => $id, 'user_id' => $i % 40 === 0 ? $candidateId : $author, 'community_id' => $i % 4 === 0 ? $communityIds[$i % 24] : null, 'repost_of_id' => $i > 100 && $i % 15 === 0 ? $postIds[$i - 100] : null, 'content' => "Publication {$i} : retour d'expérience sur mon parcours et conseils pour les entretiens.", 'created_at' => $at, 'updated_at' => $at];
            $postIds[] = $id;
        }
        $this->ins('posts', $posts);
        $comments = [];
        $likes = [];
        $attachments = [];
        foreach ($postIds as $i => $postId) {
            for ($k = 0; $k < 3; $k++) {
                $comments[] = ['id' => $this->uuid(), 'post_id' => $postId, 'user_id' => $this->pick($userIds), 'content' => 'Merci pour ce partage !', 'created_at' => $this->past(60), 'updated_at' => $this->now];
            }
            $likers = [];
            for ($k = 0; $k < 8; $k++) {
                $likers[$this->pick($userIds)] = true;
            }
            if ($i % 2 === 0) {
                $likers[$candidateId] = true;
            }
            foreach (array_keys($likers) as $liker) {
                $likes[] = ['id' => $this->uuid(), 'post_id' => $postId, 'user_id' => $liker, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
            if ($i % 4 === 0) {
                $attachments[] = ['id' => $this->uuid(), 'post_id' => $postId, 'type' => 'image', 'path' => "posts/bench-{$i}.jpg", 'name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size' => 80000, 'position' => 0, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('post_comments', $comments);
        $this->ins('post_likes', $likes);
        $this->ins('post_attachments', $attachments);

        // --- Formation -----------------------------------------------------------
        $categoryIds = [];
        $rows = [];
        foreach (['Développement', 'Marketing', 'Finance', 'Design', 'Management', 'Data', 'Langues', 'Bureautique'] as $i => $name) {
            $id = $this->uuid();
            $categoryIds[] = $id;
            $rows[] = ['id' => $id, 'name' => $name, 'slug' => 'bench-'.Str::slug($name), 'description' => null, 'icon' => 'book', 'sort_order' => $i, 'is_active' => 1, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('course_categories', $rows);
        $instructorIds = [];
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $id = $this->uuid();
            $instructorIds[] = $id;
            $rows[] = ['id' => $id, 'name' => "Formateur {$i}", 'headline' => 'Expert métier', 'bio' => 'Quinze ans de pratique.', 'country' => 'CI', 'is_active' => 1, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('instructors', $rows);
        $courseIds = [];
        $courses = [];
        $lessons = [];
        $lessonsByCourse = [];
        for ($i = 0; $i < 80; $i++) {
            $id = $this->uuid();
            $courseIds[] = $id;
            $courses[] = ['id' => $id, 'title' => "Formation {$i}", 'slug' => "formation-bench-{$i}", 'subtitle' => 'De zéro à opérationnel', 'description' => 'Programme complet.', 'category' => null, 'category_id' => $categoryIds[$i % 8], 'provider' => 'Goriya', 'instructor_id' => $instructorIds[$i % 15], 'level' => ['BEGINNER', 'INTERMEDIATE', 'ADVANCED'][$i % 3], 'language' => 'fr', 'subtitle_languages' => '[]', 'learning_outcomes' => json_encode(['Maîtriser les bases', 'Réaliser un projet']), 'duration_hours' => 10, 'is_free' => $i % 10 === 0 ? 1 : 0, 'is_published' => 1, 'lessons_count' => 20, 'total_minutes' => 200, 'published_at' => $this->past(100), 'is_active' => 1, 'created_at' => $this->now, 'updated_at' => $this->now];
            for ($l = 0; $l < 20; $l++) {
                $lessonId = $this->uuid();
                $lessonsByCourse[$id][] = $lessonId;
                $lessons[] = ['id' => $lessonId, 'course_id' => $id, 'section' => 'Module '.intdiv($l, 5), 'title' => "Leçon {$l}", 'description' => null, 'video_url' => 'https://www.youtube.com/watch?v=bench'.$l, 'video_provider' => 'YOUTUBE', 'duration_seconds' => 600, 'position' => $l, 'is_free_preview' => $l === 0 ? 1 : 0, 'subtitles' => '[]', 'resources' => '[]', 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('courses', $courses);
        $this->ins('course_lessons', $lessons);
        $enrollments = [];
        $progress = [];
        $enroll = function (string $userId, string $courseId, int $done) use (&$enrollments, &$progress, $lessonsByCourse) {
            $enrollments[$userId.$courseId] = ['id' => $this->uuid(), 'user_id' => $userId, 'course_id' => $courseId, 'progress' => $done * 5, 'status' => $done === 20 ? 'COMPLETED' : 'IN_PROGRESS', 'enrolled_at' => $this->now, 'completed_at' => $done === 20 ? $this->now : null, 'created_at' => $this->now, 'updated_at' => $this->now];
            foreach (array_slice($lessonsByCourse[$courseId], 0, $done) as $lessonId) {
                $progress[$userId.$lessonId] = ['id' => $this->uuid(), 'user_id' => $userId, 'lesson_id' => $lessonId, 'course_id' => $courseId, 'last_position_seconds' => 600, 'completed_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        };
        foreach (array_slice($courseIds, 0, 15) as $i => $courseId) {
            $enroll($candidateId, $courseId, $i % 2 ? 20 : 8);
        }
        for ($i = 0; $i < 6000; $i++) {
            $enroll($userIds[$i], $courseIds[$i % 80], mt_rand(0, 6));
        }
        $this->ins('enrollments', array_values($enrollments));
        $this->ins('lesson_progress', array_values($progress));
        $this->ins('course_bookmarks', array_map(fn ($c) => ['id' => $this->uuid(), 'user_id' => $candidateId, 'course_id' => $c, 'created_at' => $this->now, 'updated_at' => $this->now], array_slice($courseIds, 20, 12)));
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = ['id' => $this->uuid(), 'user_id' => $i < 10 ? $candidateId : $userIds[$i], 'course_id' => $courseIds[$i % 80], 'title' => "Projet {$i}", 'description' => 'Mise en pratique.', 'link_url' => 'https://github.com/bench/projet', 'status' => $i % 2 ? 'REVIEWED' : 'SUBMITTED', 'feedback' => $i % 2 ? 'Bon travail.' : null, 'reviewed_at' => $i % 2 ? $this->now : null, 'created_at' => $this->past(60), 'updated_at' => $this->now];
        }
        $this->ins('course_projects', $rows);

        // --- Contenus IA du candidat et profils ---------------------------------
        $cvData = json_encode(['prenom' => 'Bench', 'nom' => 'Candidat', 'titre' => 'Développeur', 'experiences' => [['poste' => 'Développeur', 'entreprise' => 'Orange CI', 'debut' => '2021', 'fin' => '2024']], 'formations' => [['diplome' => 'Master Informatique', 'ecole' => 'INP-HB']], 'competences' => [['nom' => 'PHP'], ['nom' => 'React']]]);
        $profileData = json_encode(['title' => 'Développeur full-stack', 'summary' => 'Cinq ans d\'expérience.', 'skills' => ['PHP', 'Laravel', 'React', 'SQL'], 'experiences' => [['title' => 'Développeur', 'company' => 'Orange CI', 'start' => '2021', 'end' => '2024']], 'education' => [['degree' => 'Master', 'school' => 'INP-HB']], 'languages' => ['Français', 'Anglais']]);
        $cvs = [['id' => $this->uuid(), 'user_id' => $candidateId, 'data' => $cvData, 'step' => 4, 'created_at' => $this->now, 'updated_at' => $this->now]];
        $profiles = [['id' => $this->uuid(), 'user_id' => $candidateId, 'data' => $profileData, 'created_at' => $this->now, 'updated_at' => $this->now]];
        $public = [['id' => $this->uuid(), 'user_id' => $candidateId, 'slug' => 'bench-candidat', 'theme' => 'DEFAULT', 'is_public' => 1, 'seo_meta' => null, 'created_at' => $this->now, 'updated_at' => $this->now]];
        foreach (array_slice($userIds, 0, 3000) as $i => $userId) {
            $cvs[] = ['id' => $this->uuid(), 'user_id' => $userId, 'data' => $cvData, 'step' => 4, 'created_at' => $this->now, 'updated_at' => $this->now];
            $profiles[] = ['id' => $this->uuid(), 'user_id' => $userId, 'data' => $profileData, 'created_at' => $this->now, 'updated_at' => $this->now];
            $public[] = ['id' => $this->uuid(), 'user_id' => $userId, 'slug' => "membre-bench-{$i}", 'theme' => 'DEFAULT', 'is_public' => $i % 5 ? 1 : 0, 'seo_meta' => null, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('cvs', $cvs);
        $this->ins('cv_profiles', $profiles);
        $this->ins('public_profiles', $public);

        $portfolios = [];
        foreach (array_merge([$candidateId], array_slice($userIds, 0, 3000)) as $i => $userId) {
            $portfolios[] = ['id' => $this->uuid(), 'title' => "Portfolio {$i}", 'description' => 'Réalisations et projets.', 'views' => mt_rand(0, 900), 'downloads' => mt_rand(0, 90), 'likes' => mt_rand(0, 200), 'created_date' => $this->past(200), 'user_id' => $userId, 'skills' => json_encode(['PHP', 'Laravel', 'React']), 'theme' => 'DEFAULT', 'status' => 'PUBLISHED', 'photo_path' => null, 'details' => null, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('portfolios', $portfolios);

        $pitches = [];
        $presentations = [];
        $research = [];
        $threads = [];
        $chat = [];
        foreach (array_merge(array_fill(0, 20, $candidateId), array_slice($userIds, 0, 800)) as $i => $userId) {
            $at = $this->past(120);
            $pitches[] = ['id' => $this->uuid(), 'user_id' => $userId, 'job_offer_id' => null, 'type' => 'EMPLOI', 'description' => 'Pitch de présentation.', 'format' => 'TEXT', 'content' => str_repeat('Bonjour, je suis développeur passionné. ', 20), 'score' => json_encode(['clarte' => 80, 'impact' => 70]), 'status' => 'READY', 'is_public' => 0, 'created_at' => $at, 'updated_at' => $at];
            $presentations[] = ['id' => $this->uuid(), 'user_id' => $userId, 'title' => "Présentation {$i}", 'type' => 'SLIDES', 'brief' => 'Présenter mon projet.', 'content' => json_encode(['slides' => array_map(fn ($n) => ['title' => "Diapositive {$n}", 'bullets' => ['Point A', 'Point B', 'Point C']], range(1, 10))]), 'created_at' => $at, 'updated_at' => $at];
            $research[] = ['id' => $this->uuid(), 'user_id' => $userId, 'company_name' => "Entreprise {$i}", 'result' => json_encode(['mode' => 'connaissances', 'resume' => str_repeat('Synthèse de l\'entreprise. ', 60), 'dirigeants' => [], 'actualites' => [], 'sources' => []]), 'is_favorite' => $i % 5 === 0 ? 1 : 0, 'created_at' => $at, 'updated_at' => $at];
            $threadId = $this->uuid();
            $threads[] = ['id' => $threadId, 'user_id' => $userId, 'title' => "Conversation {$i}", 'created_at' => $at, 'updated_at' => $at];
            for ($m = 0; $m < 12; $m++) {
                $chat[] = ['id' => $this->uuid(), 'thread_id' => $threadId, 'role' => $m % 2 ? 'ASSISTANT' : 'USER', 'content' => $m % 2 ? str_repeat('Voici ma réponse détaillée. ', 30) : 'Comment préparer mon entretien ?', 'attachments' => null, 'created_at' => $at->copy()->addMinutes($m), 'updated_at' => $at];
            }
        }
        $this->ins('pitches', $pitches);
        $this->ins('presentations', $presentations);
        $this->ins('research_queries', $research);
        $this->ins('chat_threads', $threads);
        $this->ins('chat_messages', $chat);

        // --- Back-office ---------------------------------------------------------
        $rows = [];
        for ($i = 0; $i < 60000; $i++) {
            $at = $this->past(365);
            $rows[] = ['id' => $this->uuid(), 'user_id' => $i % 3 ? $this->pick($userIds) : $enterpriseId, 'user_name' => 'Utilisateur', 'user_email' => 'u@bench.test', 'user_role' => 'USER', 'action' => $this->pick(['created', 'updated', 'updated', 'deleted', 'login']), 'auditable_type' => $this->pick(['App\\Models\\Candidature', 'App\\Models\\JobOffer', 'App\\Models\\User', 'App\\Models\\Notification']), 'auditable_id' => $this->pick($jobIds), 'old_values' => '{}', 'new_values' => '{"status":"EN_COURS"}', 'url' => '/candidatures', 'method' => 'PATCH', 'ip_address' => '127.0.0.1', 'user_agent' => 'bench', 'created_at' => $at];
        }
        $this->ins('audit_logs', $rows);

        $rows = [];
        $partnerIds = [];
        for ($i = 0; $i < 19500; $i++) {
            $id = $this->uuid();
            $partnerIds[] = $id;
            $rows[] = ['id' => $id, 'ncc' => 'NCC'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'company_name' => "Société {$i} SARL", 'email' => $i % 3 ? "contact{$i}@societe.test" : null, 'email_valid' => $i % 3 ? 1 : 0, 'contact_name' => null, 'contact_phone' => null, 'sector' => $this->pick(['Commerce', 'BTP', 'Services', 'Industrie', 'Transport', 'Agriculture']), 'activity_label' => 'Activité', 'city' => $this->pick(['Abidjan', 'Bouaké', 'San-Pédro', 'Korhogo']), 'commune' => 'Cocody', 'address' => null, 'company_size' => 'PME', 'ca_tranche' => 'T2', 'workforce_tranche' => 'E2', 'first_exercise_year' => '2015', 'status' => $this->pick(['new', 'new', 'new', 'contacted', 'interested']), 'source' => 'CCI-CI', 'notes' => null, 'raw_data' => null, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('potential_partners', $rows);
        $campaigns = [];
        $recipients = [];
        for ($c = 0; $c < 5; $c++) {
            $campaignId = $this->uuid();
            $campaigns[] = ['id' => $campaignId, 'name' => "Campagne {$c}", 'subject' => 'Découvrez Goriya', 'body_html' => '<p>Bonjour</p>', 'status' => 'sent', 'target_filters' => '{}', 'total_recipients' => 3000, 'sent_count' => 2900, 'failed_count' => 100, 'created_by' => $adminId, 'sent_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now];
            foreach (array_slice($partnerIds, $c * 3000, 3000) as $i => $partnerId) {
                $recipients[] = ['id' => $this->uuid(), 'mail_campaign_id' => $campaignId, 'potential_partner_id' => $partnerId, 'status' => $i % 30 ? 'sent' : 'failed', 'error_message' => null, 'sent_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now];
            }
        }
        $this->ins('mail_campaigns', $campaigns);
        $this->ins('mail_campaign_recipients', $recipients);

        // Tableaux « historiques » du back-office (hérités du portage NestJS).
        foreach ([
            'interview_sessions' => fn ($i) => ['candidate_name' => "Candidat {$i}", 'candidate_email' => "c{$i}@bench.test", 'position' => 'Développeur', 'duration' => 30, 'score' => mt_rand(40, 95), 'status' => $this->pick(['ACTIVE', 'COMPLETED', 'SCHEDULED']), 'start_time' => $this->past(200), 'feedback' => null],
            'matching_results' => fn ($i) => ['candidate_name' => "Candidat {$i}", 'candidate_email' => "c{$i}@bench.test", 'position' => 'Développeur', 'company' => 'Bench Corp', 'matching_score' => mt_rand(40, 95), 'status' => $this->pick(['NOUVEAU', 'EN_COURS', 'FINALISE']), 'match_date' => $this->past(200)],
            'scoring_results' => fn ($i) => ['candidate_name' => "Candidat {$i}", 'candidate_email' => "c{$i}@bench.test", 'position' => 'Développeur', 'overall_score' => mt_rand(40, 95), 'criteria' => json_encode(['technique' => 80, 'experience' => 70]), 'analysis_date' => $this->past(200), 'status' => 'COMPLETED'],
            'cv_analysis' => fn ($i) => ['filename' => "cv-{$i}.pdf", 'analysis_score' => mt_rand(40, 95), 'upload_date' => $this->past(200), 'status' => 'COMPLETED', 'recommendations' => json_encode(['Ajouter des chiffres'])],
            'calendar_events' => fn ($i) => ['title' => "Événement {$i}", 'type' => $this->pick(['ENTRETIEN', 'FORMATION', 'REUNION']), 'start_time' => $this->now->copy()->addDays(mt_rand(-90, 90)), 'end_time' => $this->now->copy()->addDays(91), 'location' => 'Abidjan', 'status' => 'CONFIRMED', 'participants' => '[]'],
        ] as $table => $factory) {
            $rows = [];
            for ($i = 0; $i < 6000; $i++) {
                $rows[] = ['id' => $this->uuid(), ...$factory($i), 'created_at' => $this->now, 'updated_at' => $this->now];
            }
            $this->ins($table, $rows);
        }

        // --- Codes promo et API B2B ----------------------------------------------
        $influencerIds = [];
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $id = $this->uuid();
            $influencerIds[] = $id;
            $rows[] = ['id' => $id, 'name' => "Influenceur {$i}", 'email' => "influenceur{$i}@bench.test", 'phone' => null, 'default_commission_rate' => 10, 'notes' => null, 'status' => 'ACTIVE', 'created_by' => $adminId, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('influencers', $rows);
        $campaignId = $this->uuid();
        $this->ins('promo_campaigns', [['id' => $campaignId, 'name' => 'Rentrée', 'description' => null, 'discount_type' => 'PERCENTAGE', 'discount_value' => 20, 'applicable_user_types' => '["USER"]', 'applicable_plan_ids' => null, 'min_plan_price' => null, 'starts_at' => null, 'ends_at' => null, 'status' => 'ACTIVE', 'created_by' => $adminId, 'created_at' => $this->now, 'updated_at' => $this->now]]);
        $codeIds = [];
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $id = $this->uuid();
            $codeIds[] = $id;
            $rows[] = ['id' => $id, 'campaign_id' => $campaignId, 'influencer_id' => $influencerIds[$i % 30], 'code' => "BENCH{$i}", 'discount_type' => null, 'discount_value' => null, 'commission_rate' => 10, 'max_uses' => null, 'used_count' => 10, 'max_uses_per_user' => 1, 'starts_at' => null, 'ends_at' => null, 'is_active' => 1, 'created_by' => $adminId, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('promo_codes', $rows);
        $rows = [];
        foreach (array_slice($txs, 0, 600) as $i => $tx) {
            $rows[] = ['id' => $this->uuid(), 'promo_code_id' => $codeIds[$i % 60], 'influencer_id' => $influencerIds[$i % 30], 'user_id' => $tx['user_id'], 'transaction_id' => $tx['id'], 'subscription_id' => null, 'plan_id' => $tx['plan_id'], 'original_amount' => 5000, 'discount_amount' => 1000, 'final_amount' => 4000, 'currency' => 'XOF', 'commission_rate' => 10, 'commission_amount' => 400, 'status' => $i % 5 ? 'CONFIRMED' : 'PENDING', 'payout_id' => null, 'redeemed_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->ins('promo_code_redemptions', $rows);

        $clientId = $this->uuid();
        $this->ins('api_clients', [['id' => $clientId, 'company_id' => $companyId, 'name' => 'Bench ATS', 'token_hash' => hash('sha256', self::API_TOKEN), 'is_sandbox' => 0, 'is_active' => 1, 'rate_limit_per_minute' => 100000, 'created_at' => $this->now, 'updated_at' => $this->now]]);
        $this->ins('webhooks', [['id' => $this->uuid(), 'api_client_id' => $clientId, 'url' => 'https://example.test/hook', 'events' => '["candidature.created"]', 'secret' => 'bench', 'is_active' => 0, 'created_at' => $this->now, 'updated_at' => $this->now]]);

        $this->command?->info('✅ Jeu de données de performance prêt.');
    }

    /** @return array<string, mixed> */
    private function job(string $id, string $title, string $companyId, Carbon $created, string $slug, string $status = 'ACTIVE'): array
    {
        return [
            'id' => $id, 'title' => $title, 'slug' => $slug, 'location' => $this->pick(['Abidjan', 'Bouaké', 'Yamoussoukro', 'San-Pédro']), 'remote' => mt_rand(0, 4) === 0 ? 1 : 0,
            'type' => $this->pick(['CDI', 'CDD', 'STAGE', 'FREELANCE']), 'experience' => $this->pick(['JUNIOR', 'INTERMEDIAIRE', 'SENIOR', 'EXPERT']), 'salary' => '400 000 - 600 000 FCFA',
            'description' => str_repeat('Vous rejoignez une équipe dynamique et participez aux projets structurants. ', 8), 'image' => null, 'benefits' => 'Assurance, primes',
            'status' => $status, 'publish_date' => $created->toDateString(), 'end_date' => $created->copy()->addDays(120)->toDateString(), 'applicants' => mt_rand(0, 60), 'company_id' => $companyId,
            'requirements' => json_encode(['PHP', 'Laravel', 'SQL', 'Communication']), 'created_at' => $created, 'updated_at' => $created,
        ];
    }

    /** @return array<string, mixed> */
    private function candidature(string $id, string $userId, string $name, string $email, string $jobId, string $status, ?string $resumeId): array
    {
        $applied = $this->past(180);

        return [
            'id' => $id, 'candidate_name' => $name, 'candidate_email' => $email, 'candidate_phone' => '+2250700000000', 'candidate_location' => 'Abidjan',
            'cover_letter' => 'Madame, Monsieur, je vous adresse ma candidature.', 'resume_id' => $resumeId, 'status' => $status, 'score' => mt_rand(0, 95),
            'applied_date' => $applied, 'user_id' => $userId, 'job_offer_id' => $jobId, 'pitch_id' => null, 'pipeline_stage' => null, 'stage_changed_at' => null,
            'rejection_reason' => null, 'created_at' => $applied, 'updated_at' => $applied,
        ];
    }

    /** @return array<string, mixed> */
    private function notification(string $userId, int $i): array
    {
        $at = $this->past(120);

        return ['id' => $this->uuid(), 'user_id' => $userId, 'type' => ['MESSAGE', 'APPLICATION_STATUS', 'SYSTEM'][$i % 3], 'title' => 'Candidature mise à jour', 'body' => 'Ta candidature est en cours de traitement.', 'link' => '/mes-offres', 'is_read' => $i % 4 ? 1 : 0, 'read_at' => $i % 4 ? $at : null, 'created_at' => $at, 'updated_at' => $at];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function ins(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        $this->command?->line(sprintf('   %-28s %6d', $table, count($rows)));
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function past(int $maxDays): Carbon
    {
        return $this->now->copy()->subMinutes(mt_rand(1, $maxDays * 1440));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }
}
