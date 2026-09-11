<?php

namespace App\Services;

use App\Contracts\HrInsightsServiceInterface;
use App\Enums\SurveyQuestionType;
use App\Enums\SurveyStatus;
use App\Models\Employee;
use App\Models\EmployeeSurvey;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Enquêtes internes (satisfaction/évaluation) — création côté entreprise,
 * réponse anonyme côté employé, agrégation IA des résultats. Aucune méthode
 * de ce service ne renvoie une SurveyResponse individuelle à l'appelant
 * entreprise — seulement des agrégats, voir stats().
 */
class EmployeeSurveyService
{
    public function __construct(private readonly HrInsightsServiceInterface $hrInsights) {}

    public function listForCompany(string $companyId): Collection
    {
        return EmployeeSurvey::where('company_id', $companyId)->withCount('responses')->orderByDesc('created_at')->get();
    }

    public function find(string $id, string $companyId): ?EmployeeSurvey
    {
        return EmployeeSurvey::where('company_id', $companyId)->find($id);
    }

    /**
     * Évaluations actives visibles par cet employé dans son espace employé :
     * celles de son entreprise, non ciblées sur un département (`null`) ou
     * ciblées sur le sien.
     */
    public function listForEmployee(Employee $employee): Collection
    {
        return EmployeeSurvey::where('company_id', $employee->company_id)
            ->where('status', SurveyStatus::ACTIVE)
            ->where(function ($query) use ($employee) {
                $query->whereNull('department')
                    ->orWhere('department', $employee->department);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array{title: string, description?: string, questions: array<int, array{id: string, question: string, type: string}>, dueDate?: ?string, department?: ?string}  $data
     */
    public function create(User $companyUser, array $data): EmployeeSurvey
    {
        return EmployeeSurvey::create([
            'company_id' => $companyUser->company_id,
            'created_by' => $companyUser->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'questions' => $data['questions'],
            'status' => SurveyStatus::DRAFT,
            'due_date' => $data['dueDate'] ?? null,
            'department' => $data['department'] ?? null,
        ]);
    }

    /**
     * Clôture automatique des évaluations actives dont l'échéance est
     * dépassée — appelé par CloseExpiredSurveysCommand (planifié quotidien).
     *
     * @return int Nombre d'évaluations clôturées
     */
    public function closeExpired(): int
    {
        return EmployeeSurvey::where('status', SurveyStatus::ACTIVE)
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today())
            ->update(['status' => SurveyStatus::CLOSED]);
    }

    public function updateStatus(EmployeeSurvey $survey, SurveyStatus $status): EmployeeSurvey
    {
        $survey->update(['status' => $status]);

        return $survey;
    }

    /**
     * Modifie une évaluation. Titre, description, échéance et département
     * restent modifiables à tout moment ; les questions ne le sont plus dès
     * qu'une réponse a été reçue — les changer romprait le sens des réponses
     * déjà enregistrées (et de leurs statistiques agrégées).
     *
     * @param  array{title?: string, description?: ?string, questions?: array<int, array{id: string, question: string, type: string}>, dueDate?: ?string, department?: ?string}  $data
     */
    public function update(EmployeeSurvey $survey, array $data): EmployeeSurvey
    {
        if (array_key_exists('questions', $data) && $survey->responses()->exists()) {
            abort(400, 'Les questions ne sont plus modifiables : cette évaluation a déjà reçu des réponses.');
        }

        $attributes = [];
        foreach (['title', 'description', 'questions'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }
        if (array_key_exists('dueDate', $data)) {
            $attributes['due_date'] = $data['dueDate'];
        }
        if (array_key_exists('department', $data)) {
            $attributes['department'] = $data['department'];
        }

        $survey->update($attributes);

        return $survey;
    }

    public function delete(EmployeeSurvey $survey): void
    {
        $survey->delete();
    }

    /**
     * @param  array<int, array{questionId: string, value: int|string}>  $answers
     */
    public function submitResponse(EmployeeSurvey $survey, User $employee, array $answers): SurveyResponse
    {
        if ($survey->status !== SurveyStatus::ACTIVE) {
            abort(400, "Cette enquête n'est pas ouverte aux réponses");
        }

        if (SurveyResponse::where('survey_id', $survey->id)->where('user_id', $employee->id)->exists()) {
            abort(409, 'Vous avez déjà répondu à cette enquête');
        }

        return SurveyResponse::create([
            'survey_id' => $survey->id,
            'user_id' => $employee->id,
            'answers' => $answers,
        ]);
    }

    /**
     * Agrège les réponses : moyenne par question RATING, analyse IA des
     * questions TEXT (tendances/friction/recommandations) — jamais de
     * réponse individuelle ni de user_id dans le résultat.
     *
     * @return array{participationCount: int, ratings: array<string, float>, trends: array<int, string>, frictionPoints: array<int, string>, recommendations: array<int, string>}
     */
    public function stats(EmployeeSurvey $survey): array
    {
        $responses = SurveyResponse::where('survey_id', $survey->id)->get();

        $questionTypes = collect($survey->questions)->keyBy('id')->map(fn ($q) => $q['type']);

        $ratingSums = [];
        $ratingCounts = [];
        $textAnswers = [];

        foreach ($responses as $response) {
            foreach ($response->answers as $answer) {
                $questionId = $answer['questionId'] ?? null;
                $type = $questionTypes[$questionId] ?? null;

                if ($type === SurveyQuestionType::RATING->value) {
                    $ratingSums[$questionId] = ($ratingSums[$questionId] ?? 0) + (float) $answer['value'];
                    $ratingCounts[$questionId] = ($ratingCounts[$questionId] ?? 0) + 1;
                } elseif ($type === SurveyQuestionType::TEXT->value && ! empty($answer['value'])) {
                    $textAnswers[] = (string) $answer['value'];
                }
            }
        }

        $ratings = [];
        foreach ($ratingSums as $questionId => $sum) {
            $ratings[$questionId] = round($sum / $ratingCounts[$questionId], 2);
        }

        $insights = $this->hrInsights->analyzeSurveyResponses($textAnswers);

        return [
            'participationCount' => $responses->count(),
            'ratings' => $ratings,
            'trends' => $insights['trends'],
            'frictionPoints' => $insights['frictionPoints'],
            'recommendations' => $insights['recommendations'],
        ];
    }
}
