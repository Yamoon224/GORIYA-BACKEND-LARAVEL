<?php

namespace Database\Seeders\Support;

/**
 * Portage des données/fonctions du seeder NestJS
 * (backend/src/database/seeders/data/ivorian.data.ts) pour garder un jeu de
 * données cohérent entre les deux backends.
 */
class IvorianData
{
    public const CITIES = [
        'Abidjan', 'Bouaké', 'Yamoussoukro', 'San-Pédro', 'Korhogo',
        'Daloa', 'Man', 'Gagnoa', 'Divo', 'Abengourou',
        'Grand-Bassam', 'Bingerville',
    ];

    public const SECTORS = [
        'Informatique', 'Marketing', 'Finance', 'Banque', 'Assurance',
        'Communication', 'Ressources humaines', 'Santé', 'Industrie',
        'Logistique', 'Juridique', 'Comptabilité', 'Distribution',
        'Conseil', 'Publicité', 'Graphisme', 'Éducation', 'Audit',
    ];

    public const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];

    public const FIRST_NAMES = [
        'Yao', 'Kouadio', 'Adjoua', 'Akissi', 'Aya', 'Konan', 'Affoué',
        'Amenan', 'Kouassi', 'Fatou', 'Awa', 'Ibrahim', 'Mamadou', 'Aminata',
        'Sekou', 'Moussa', 'Aïcha', 'Bakary', 'Aristide', 'Serge', 'Christelle',
        'Nadège', 'Grace', 'Franck', 'Larissa', 'Cynthia', 'Judith', 'Pascal',
        'Hervé', 'Sandrine', 'Junior', 'Marie-Claire',
    ];

    public const LAST_NAMES = [
        'Kouassi', 'Koffi', 'Kouame', 'Yao', 'Konan', 'Aka', "N'Dri",
        'Ouattara', 'Bamba', 'Coulibaly', 'Diabaté', 'Traoré', 'Koné',
        'Diallo', 'Kra', 'Brou', 'Tanoh', 'Gnamien', 'Assi', 'Adou',
    ];

    public const JOB_TEMPLATES = [
        ['title' => 'Développeur Full Stack Senior', 'skills' => ['NestJS', 'React', 'TypeScript', 'PostgreSQL']],
        ['title' => 'Développeur Backend Node.js', 'skills' => ['NodeJS', 'NestJS', 'PostgreSQL', 'Docker']],
        ['title' => 'Développeur Mobile Flutter', 'skills' => ['Flutter', 'Dart', 'Firebase', 'REST API']],
        ['title' => 'Ingénieur DevOps', 'skills' => ['Docker', 'AWS', 'CI/CD', 'Kubernetes']],
        ['title' => 'Data Analyst', 'skills' => ['SQL', 'Power BI', 'Python', 'Excel avancé']],
        ['title' => 'Développeur Frontend React', 'skills' => ['React', 'TypeScript', 'TailwindCSS']],
        ['title' => 'Administrateur Systèmes & Réseaux', 'skills' => ['Linux', 'Windows Server', 'Réseaux', 'Sécurité']],
        ['title' => 'Responsable Marketing Digital', 'skills' => ['SEO', 'Google Ads', 'Réseaux sociaux', 'Analytics']],
        ['title' => 'Chargé de Communication', 'skills' => ['Rédaction', 'Réseaux sociaux', 'Relations presse']],
        ['title' => 'Community Manager', 'skills' => ['Réseaux sociaux', 'Canva', 'Création de contenu']],
        ['title' => 'Commercial B2B', 'skills' => ['Prospection', 'Négociation', 'CRM']],
        ['title' => 'Business Developer', 'skills' => ['Prospection', 'Négociation', 'Stratégie commerciale']],
        ['title' => 'Comptable Général', 'skills' => ['Sage', 'Excel avancé', 'Fiscalité']],
        ['title' => 'Contrôleur de Gestion', 'skills' => ['Excel avancé', 'Analyse financière', 'Reporting']],
        ['title' => 'Analyste Financier', 'skills' => ['Analyse financière', 'Excel avancé', 'Modélisation']],
        ['title' => 'Responsable Administratif et Financier', 'skills' => ['Comptabilité', 'Gestion budgétaire', 'Excel avancé']],
        ['title' => 'Chargé de Recrutement', 'skills' => ['Sourcing', 'Entretiens', 'SIRH']],
        ['title' => 'Responsable Ressources Humaines', 'skills' => ['Droit du travail', 'Gestion des talents', 'Paie']],
        ['title' => "Juriste d'Entreprise", 'skills' => ['Droit des affaires', 'Rédaction juridique', 'Contrats']],
        ['title' => 'Graphiste Maquettiste', 'skills' => ['Photoshop', 'Illustrator', 'InDesign']],
        ['title' => 'UI/UX Designer', 'skills' => ['Figma', 'Prototypage', 'Design system']],
        ['title' => 'Responsable Logistique', 'skills' => ['Gestion de stock', 'Transport', 'ERP']],
        ['title' => 'Agent de Transit', 'skills' => ['Douane', 'Transport international', 'Logistique']],
        ['title' => "Infirmier(ère) d'Entreprise", 'skills' => ["Soins d'urgence", 'Prévention santé']],
        ['title' => 'Formateur Professionnel', 'skills' => ['Pédagogie', 'Animation de formation']],
    ];

    private const EMAIL_DOMAINS = ['gmail.com', 'yahoo.fr', 'outlook.com'];

    private const COMPANY_NAME_PREFIXES = [
        'Ivoire', 'Baoulé', 'Lagune', 'Savane', 'Plateau', 'Akwaba', 'Cacao', 'Baobab',
        'Atlantique', 'Zaguié', 'Sika', 'Koras', 'Vridi', 'Yakro', 'Fraternité', 'Ébène',
        'Gbich', 'TechnoPole', 'Nouvelle Ère', 'San-Pédro', 'Abidjan', 'Bouaké',
        'Grand-Bassam', 'Comoé', 'Bandama', 'Sassandra', 'Kong', 'Fresco', 'Marahoué',
        'Éburnie', 'Wôrô', 'Diaman', 'Awalé', 'Zenith CI', 'Horizon',
    ];

    private const COMPANY_NAME_SUFFIXES = [
        'Digital Solutions', 'Tech', 'Finance CI', 'Telecom', 'Énergie', 'Trade International',
        'FinTech', 'Agro-Industries', 'Consulting', 'Digital Factory', 'Media Group', 'BTP',
        'Port Services', 'Export', 'Maritime', 'Pharma', 'Hôtellerie', 'Assurances',
        'Business Center', 'Retail', 'Cargo Logistique', 'Ressources Humaines', 'Industries',
        'Group', 'SARL', '& Associés', 'Services', 'International', 'Holding', 'Systems',
        'Innovations', 'Partners', 'Capital', 'Networks',
    ];

    private const BENEFITS_POOL = [
        'Mutuelle santé', 'Tickets restaurant', 'Prime de performance', '13ème mois',
        'Transport pris en charge', 'Formation continue', 'Télétravail partiel',
        "Prime de fin d'année", 'Congés payés', 'Environnement de travail flexible',
    ];

    private const SALARY_RANGES = [
        'JUNIOR' => [150000, 350000],
        'INTERMEDIAIRE' => [350000, 700000],
        'SENIOR' => [700000, 1500000],
        'EXPERT' => [1500000, 3000000],
    ];

    /** @param array<int, mixed> $items */
    public static function randomItem(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    public static function randomFullName(): string
    {
        return self::randomItem(self::FIRST_NAMES).' '.self::randomItem(self::LAST_NAMES);
    }

    public static function slugify(string $value): string
    {
        $value = strtolower($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-');
    }

    public static function emailFromName(string $fullName, int $uniqueSuffix): string
    {
        $parts = explode(' ', $fullName, 2);
        $first = self::slugify($parts[0] ?? 'user');
        $last = self::slugify($parts[1] ?? 'x');
        $domain = self::randomItem(self::EMAIL_DOMAINS);

        return "{$first}.{$last}{$uniqueSuffix}@{$domain}";
    }

    public static function randomIvorianPhone(): string
    {
        $prefix = self::randomItem(['01', '05', '07', '25']);
        $rest = [];
        for ($i = 0; $i < 4; $i++) {
            $rest[] = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
        }

        return "+225 {$prefix} ".implode(' ', $rest);
    }

    public static function formatSalary(string $experience): string
    {
        [$min, $max] = self::SALARY_RANGES[$experience] ?? self::SALARY_RANGES['JUNIOR'];
        $amount = (int) round((random_int($min, $max)) / 5000) * 5000;

        return number_format($amount, 0, ',', ' ').' FCFA/mois';
    }

    public static function companyLogoUrl(string $name): string
    {
        return 'https://ui-avatars.com/api/?name='.urlencode($name).'&background=2f6de6&color=fff&bold=true&size=128';
    }

    /** @param array<int, string> $skills */
    public static function buildJobDescription(string $title, string $companyName, string $city, array $skills): string
    {
        $skillsList = implode(', ', array_slice($skills, 0, 3));

        return "{$companyName} recherche un(e) ".mb_strtolower($title)." pour rejoindre son équipe basée à {$city}. "
            ."Vous interviendrez sur des missions variées liées à {$skillsList} et contribuerez directement à la croissance de l'entreprise. "
            .'Nous recherchons un profil rigoureux, autonome et force de proposition, capable de s\'intégrer rapidement dans une équipe dynamique.';
    }

    public static function buildJobBenefits(): string
    {
        $pool = self::BENEFITS_POOL;
        shuffle($pool);
        $count = 3 + random_int(0, 1);

        return implode(', ', array_slice($pool, 0, $count));
    }

    /** @return array<int, string> */
    public static function generateCompanyNames(int $count): array
    {
        $names = [];
        $usedSlugs = [];
        $safety = 0;

        while (count($names) < $count && $safety < $count * 50) {
            $safety++;
            $candidate = self::randomItem(self::COMPANY_NAME_PREFIXES).' '.self::randomItem(self::COMPANY_NAME_SUFFIXES);
            $slug = self::slugify($candidate);
            if (isset($usedSlugs[$slug])) {
                continue;
            }
            $usedSlugs[$slug] = true;
            $names[] = $candidate;
        }

        $n = 1;
        while (count($names) < $count) {
            $candidate = self::randomItem(self::COMPANY_NAME_PREFIXES).' '.self::randomItem(self::COMPANY_NAME_SUFFIXES)." {$n}";
            $slug = self::slugify($candidate);
            if (! isset($usedSlugs[$slug])) {
                $usedSlugs[$slug] = true;
                $names[] = $candidate;
            }
            $n++;
        }

        return $names;
    }
}
