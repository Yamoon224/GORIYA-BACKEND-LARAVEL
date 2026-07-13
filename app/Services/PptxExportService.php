<?php

namespace App\Services;

use App\Enums\PresentationType;
use App\Models\Presentation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * Seul point du Créateur de Présentations & Schémas où un rendu binaire est
 * produit côté serveur — l'export .pptx n'a pas d'équivalent raisonnable en
 * rendu client, contrairement au PDF/PNG/SVG (canvas/SVG côté frontend).
 * Réservé aux présentations de type SLIDES (un schéma nœuds/arêtes n'a pas
 * de représentation slide par slide sensée).
 */
class PptxExportService
{
    public function export(Presentation $presentation): string
    {
        if ($presentation->type !== PresentationType::SLIDES) {
            abort(400, "L'export .pptx n'est disponible que pour les présentations de type SLIDES");
        }

        $slides = $presentation->content['slides'] ?? [];

        $document = new PhpPresentation;
        $document->removeSlideByIndex(0);

        $this->addTitleSlide($document, $presentation->title);

        foreach ($slides as $slide) {
            $this->addContentSlide($document, $slide['title'] ?? '', $slide['bullets'] ?? []);
        }

        $filename = Str::uuid().'.pptx';
        $relativePath = "presentations/{$filename}";
        $absolutePath = Storage::disk('public')->path($relativePath);

        Storage::disk('public')->makeDirectory('presentations');
        IOFactory::createWriter($document, 'PowerPoint2007')->save($absolutePath);

        return "/storage/{$relativePath}";
    }

    private function addTitleSlide(PhpPresentation $document, string $title): void
    {
        $slide = $document->createSlide();

        $shape = $slide->createRichTextShape()
            ->setHeight(150)
            ->setWidth(800)
            ->setOffsetX(60)
            ->setOffsetY(220);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $run = $shape->createTextRun($title);
        $run->getFont()->setBold(true)->setSize(36)->setColor(new Color('FF1E293B'));
    }

    /**
     * @param  array<int, string>  $bullets
     */
    private function addContentSlide(PhpPresentation $document, string $title, array $bullets): void
    {
        $slide = $document->createSlide();

        $titleShape = $slide->createRichTextShape()
            ->setHeight(70)
            ->setWidth(860)
            ->setOffsetX(40)
            ->setOffsetY(30);
        $titleRun = $titleShape->createTextRun($title);
        $titleRun->getFont()->setBold(true)->setSize(28)->setColor(new Color('FF1E293B'));

        $bodyShape = $slide->createRichTextShape()
            ->setHeight(400)
            ->setWidth(860)
            ->setOffsetX(40)
            ->setOffsetY(120);

        foreach ($bullets as $index => $bullet) {
            $paragraph = $index === 0 ? $bodyShape->getActiveParagraph() : $bodyShape->createParagraph();
            $paragraph->getBulletStyle()->setBulletChar('•');
            $run = $paragraph->createTextRun($bullet);
            $run->getFont()->setSize(20);
        }
    }
}
