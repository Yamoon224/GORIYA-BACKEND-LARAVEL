<?php

namespace App\Contracts;

/**
 * Frontière vers le fournisseur d'analyse IA utilisé par le Créateur de
 * Présentations & Schémas — implémentée par AnthropicPresentationService.
 * La structure retournée dépend de $type : slides[] pour SLIDES,
 * nodes[]/edges[] pour SCHEMA (organigramme, mind map, diagramme de flux,
 * roadmap — un même modèle nœuds/arêtes couvre les quatre).
 */
interface PresentationAiServiceInterface
{
    /**
     * @return array{slides: array<int, array{title: string, bullets: array<int, string>}>}|array{nodes: array<int, array{id: string, label: string}>, edges: array<int, array{from: string, to: string, label?: string}>}
     */
    public function generate(string $brief, string $type): array;
}
