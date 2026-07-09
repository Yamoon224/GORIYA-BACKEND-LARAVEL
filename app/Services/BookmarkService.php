<?php

namespace App\Services;

use App\Models\CompanyFollow;
use App\Models\SavedJob;

/**
 * Suivi d'entreprises et sauvegarde d'offres par un utilisateur (candidat).
 * Remplace l'ancien App\Services\Admin\AdminBookmarkService (stub Cache non
 * listable, non réellement scopé par utilisateur) par des tables réelles.
 */
class BookmarkService
{
    public function followCompany(string $companyId, string $userId): void
    {
        CompanyFollow::firstOrCreate(['user_id' => $userId, 'company_id' => $companyId]);
    }

    public function unfollowCompany(string $companyId, string $userId): void
    {
        CompanyFollow::where(['user_id' => $userId, 'company_id' => $companyId])->delete();
    }

    public function saveJob(string $jobId, string $userId): void
    {
        SavedJob::firstOrCreate(['user_id' => $userId, 'job_offer_id' => $jobId]);
    }

    public function unsaveJob(string $jobId, string $userId): void
    {
        SavedJob::where(['user_id' => $userId, 'job_offer_id' => $jobId])->delete();
    }

    /**
     * @return list<string>
     */
    public function followedCompanyIds(string $userId): array
    {
        return CompanyFollow::where('user_id', $userId)->pluck('company_id')->all();
    }

    /**
     * @return list<string>
     */
    public function savedJobIds(string $userId): array
    {
        return SavedJob::where('user_id', $userId)->pluck('job_offer_id')->all();
    }

    public function savedJobsCount(string $userId): int
    {
        return SavedJob::where('user_id', $userId)->count();
    }
}
