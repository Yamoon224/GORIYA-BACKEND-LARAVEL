<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Enums\CVStatus;
use App\Http\Concerns\HandlesUniqueViolations;
use App\Models\CvAnalysis;
use App\Repositories\Contracts\CvAnalysisRepositoryInterface;
use App\Services\Concerns\MapsFieldsToColumns;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirroir de backend/src/cv-analysis/cv-analysis.service.ts.
 */
class CvAnalysisService
{
    use HandlesUniqueViolations, MapsFieldsToColumns;

    private const ALLOWED_FILE_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly CvAnalysisRepositoryInterface $cvAnalysisRepository,
        private readonly AiAnalysisServiceInterface $aiAnalysisService,
    ) {}

    /*
    |----------------------------------------------------------------------
    | CREATE — seul le fichier compte : les autres champs du DTO sont
    | ignorés côté NestJS (toujours écrasés par analysisScore=0/status=
    | ANALYZING puis par le résultat de l'analyse IA), même comportement ici.
    |----------------------------------------------------------------------
    */
    public function create(UploadedFile $file): CvAnalysis
    {
        $binary = $file->get();
        $mimeType = $file->getMimeType();
        $originalName = $file->getClientOriginalName();

        if (! in_array($mimeType, self::ALLOWED_FILE_TYPES, true)) {
            abort(400, 'Unsupported file type. Only PDF and Word allowed.');
        }

        $storedPath = $this->storeFile($file);

        try {
            $cv = $this->cvAnalysisRepository->create([
                'filename' => $storedPath,
                'analysis_score' => 0,
                'recommendations' => [],
                'upload_date' => now(),
                'status' => CVStatus::ANALYZING,
            ]);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        try {
            $result = $this->aiAnalysisService->analyzeCV($binary, $mimeType, $originalName);
            $this->cvAnalysisRepository->update($cv, [
                'analysis_score' => $result['score'],
                'recommendations' => $result['recommendations'],
                'status' => CVStatus::COMPLETED,
            ]);
        } catch (Throwable) {
            $this->cvAnalysisRepository->update($cv, ['status' => CVStatus::FAILED]);
        }

        return $cv;
    }

    /*
    |----------------------------------------------------------------------
    | UPDATE — contrairement à create(), les champs fournis sont appliqués
    | tels quels (miroir du Object.assign(cv, data) côté NestJS).
    |----------------------------------------------------------------------
    */
    public function update(CvAnalysis $cv, array $data, ?UploadedFile $file): CvAnalysis
    {
        $mapped = [];

        if ($file) {
            $this->deleteFile($cv->filename);
            $mapped['filename'] = $this->storeFile($file);
        }

        $mapped += $this->mapFields($data, [
            'analysisScore' => 'analysis_score',
            'recommendations' => 'recommendations',
            'uploadDate' => 'upload_date',
            'status' => 'status',
        ]);

        try {
            $this->cvAnalysisRepository->update($cv, $mapped);
        } catch (QueryException $e) {
            $this->abortOnUniqueViolation($e, []);
        }

        return $cv;
    }

    public function paginate(int $page, int $limit, array $filters = []): LengthAwarePaginator
    {
        return $this->cvAnalysisRepository->paginate($page, $limit, $filters);
    }

    public function remove(CvAnalysis $cv): void
    {
        $this->deleteFile($cv->filename);
        $this->cvAnalysisRepository->delete($cv);
    }

    private function storeFile(UploadedFile $file): string
    {
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('cv-analysis', $file, $filename);

        return "/storage/cv-analysis/{$filename}";
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete('cv-analysis/'.basename($path));
        }
    }
}
