<?php

namespace App\Services;

use App\Models\Enrollment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Certificat de fin de formation — document personnel, donc stocké sur le
 * disque privé `local` dès le départ (voir EnrollmentsController::
 * downloadCertificate(), qui revérifie la propriété avant de streamer),
 * contrairement à video_path/avatar déjà publics par conception ailleurs.
 */
class CertificateService
{
    public function generate(Enrollment $enrollment): string
    {
        $enrollment->loadMissing('user', 'course');

        $document = new PhpWord;
        $section = $document->addSection();

        $section->addTextBreak(4);
        $section->addText(
            'Certificat de réussite',
            ['bold' => true, 'size' => 28, 'color' => '1D4ED8'],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak(2);
        $section->addText(
            $enrollment->user->name,
            ['bold' => true, 'size' => 22],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak();
        $section->addText(
            "a complété avec succès la formation « {$enrollment->course->title} »",
            ['size' => 16],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak();
        $section->addText(
            'Délivré le '.now()->format('d/m/Y').' par GORIYA',
            ['size' => 12, 'color' => '666666'],
            ['alignment' => Jc::CENTER],
        );

        $filename = Str::uuid().'.docx';
        $relativePath = "certificates/{$filename}";
        Storage::disk('local')->makeDirectory('certificates');
        IOFactory::createWriter($document, 'Word2007')->save(Storage::disk('local')->path($relativePath));

        return $relativePath;
    }
}
