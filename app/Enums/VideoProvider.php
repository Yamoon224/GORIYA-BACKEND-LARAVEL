<?php

namespace App\Enums;

/**
 * Hébergeur de la vidéo d'une leçon. Seul FILE (MP4/WebM en lien direct)
 * permet les sous-titres .vtt et le téléchargement hors-ligne côté client ;
 * YOUTUBE/VIMEO sont lus en iframe.
 */
enum VideoProvider: string
{
    case YOUTUBE = 'YOUTUBE';
    case VIMEO = 'VIMEO';
    case FILE = 'FILE';

    public static function detect(?string $url): self
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return match (true) {
            str_contains($host, 'youtube.com'), str_contains($host, 'youtu.be') => self::YOUTUBE,
            str_contains($host, 'vimeo.com') => self::VIMEO,
            default => self::FILE,
        };
    }
}
