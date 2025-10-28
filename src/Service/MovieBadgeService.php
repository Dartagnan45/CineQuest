<?php
// src/Service/MovieBadgeService.php
namespace App\Service;

final class MovieBadgeService
{
    // Seuils réglables (tu peux les affiner au besoin)
    private const MIN_VOTES_CHEF = 5000;
    private const MIN_VOTES_CULT = 2000;
    private const MIN_TMDb_CHEF = 8.0;
    private const MIN_IMDb_CHEF = 8.0;

    private const MIN_POP_CULT = 30.0; // TMDb popularity (approx)
    private const CLASSIC_START = 1980;
    private const CLASSIC_END   = 1999;

    /**
     * Normalise les valeurs issues d’OMDb (IMDb/RT/Metacritic) en flottants simples.
     */
    private function parseOmdbScores(?array $omdb): array
    {
        if (!$omdb) {
            return [null, null, null]; // imdb, rt, mc
        }
        $imdb = isset($omdb['imdbRating']) && $omdb['imdbRating'] !== 'N/A'
            ? (float)$omdb['imdbRating']
            : null;

        $rt = null;
        if (!empty($omdb['Ratings']) && is_array($omdb['Ratings'])) {
            foreach ($omdb['Ratings'] as $r) {
                if (($r['Source'] ?? null) === 'Rotten Tomatoes') {
                    // "88%" -> 88.0
                    $rt = isset($r['Value']) ? (float)str_replace('%', '', $r['Value']) : null;
                } elseif (($r['Source'] ?? null) === 'Metacritic') {
                    // "73/100" -> 73.0
                    $rtxt = $r['Value'] ?? null;
                    if ($rtxt && strpos($rtxt, '/') !== false) {
                        $parts = explode('/', $rtxt, 2);
                        $mc = (float)$parts[0];
                        // on renvoie via $mc (la variable $rt continue d'être le RT)
                    }
                }
            }
        }

        // Metacritic direct si exposé côté contrôleur
        $mc = null;
        if (isset($omdb['MetacriticScore']) && $omdb['MetacriticScore'] !== 'N/A') {
            $mc = (float)$omdb['MetacriticScore'];
        } else {
            // sinon, essaye de récupérer via Ratings si trouvé plus haut
            if (!empty($omdb['Ratings']) && is_array($omdb['Ratings'])) {
                foreach ($omdb['Ratings'] as $r) {
                    if (($r['Source'] ?? null) === 'Metacritic') {
                        $txt = $r['Value'] ?? null;
                        if ($txt && strpos($txt, '/') !== false) {
                            $parts = explode('/', $txt, 2);
                            $mc = (float)$parts[0];
                        }
                    }
                }
            }
        }

        return [$imdb, $rt, $mc];
    }

    /**
     * Retourne l'année numérique (ou null).
     */
    private function getYear(array $item, bool $isSeries): ?int
    {
        $key = $isSeries ? 'first_air_date' : 'release_date';
        if (empty($item[$key])) return null;
        return (int)substr($item[$key], 0, 4);
    }

    /**
     * Décide les badges “complets” (detail page) avec OMDb & TMDb.
     * Retourne un tableau de badges = [
     *   ['key'=>'chef', 'label'=>'Chef-d’œuvre', 'icon'=>'fa-trophy', 'reason'=>'...'],
     *   ...
     * ]
     */
    public function decideBadges(array $tmdb, ?array $omdb, bool $isSeries): array
    {
        $badges = [];

        $voteAverage = (float)($tmdb['vote_average'] ?? 0);
        $voteCount   = (int)($tmdb['vote_count'] ?? 0);
        $popularity  = (float)($tmdb['popularity'] ?? 0.0);
        $genres      = is_array($tmdb['genres'] ?? null)
            ? array_column($tmdb['genres'], 'id')
            : (array)($tmdb['genre_ids'] ?? []);
        $year        = $this->getYear($tmdb, $isSeries);

        [$imdb, $rt, $mc] = $this->parseOmdbScores($omdb);

        // 1) Chef-d’œuvre
        $isChef = ($voteAverage >= self::MIN_TMDb_CHEF)
            && ($imdb !== null && $imdb >= self::MIN_IMDb_CHEF)
            && ($voteCount >= self::MIN_VOTES_CHEF);
        if ($isChef) {
            $reason = sprintf(
                "TMDb %.1f/10, IMDb %.1f/10, %s votes",
                $voteAverage,
                $imdb,
                number_format($voteCount, 0, '.', ' ')
            );
            if ($rt !== null) {
                $reason .= ", Rotten Tomatoes {$rt}%";
            }
            if ($mc !== null) {
                $reason .= ", Metacritic {$mc}/100";
            }
            $badges[] = ['key' => 'chef', 'label' => "Chef-d’œuvre", 'icon' => 'fa-trophy', 'reason' => $reason];
        }

        // 2) Film culte
        //   Critères : ancienneté + popularité durable + base de votes solide
        $isCulte = ($year !== null && $year <= (int)date('Y') - 10)
            && ($voteCount >= self::MIN_VOTES_CULT)
            && ($popularity >= self::MIN_POP_CULT);
        if ($isCulte) {
            $reason = sprintf(
                "Culte : année %d, %s votes, popularité %.1f",
                $year,
                number_format($voteCount, 0, '.', ' '),
                $popularity
            );
            $badges[] = ['key' => 'culte', 'label' => "Film culte", 'icon' => 'fa-fire', 'reason' => $reason];
        }

        // 3) Classique années 80/90 (films seulement)
        if (!$isSeries && $year !== null && $year >= self::CLASSIC_START && $year <= self::CLASSIC_END) {
            $badges[] = [
                'key'   => 'classic',
                'label' => 'Classique 80/90',
                'icon'  => 'fa-popcorn',
                'reason' => "Sorti en {$year}"
            ];
        }

        // 4) Culte par genre – on met un badge genre si culte ET genre iconique
        //    Idées : 878 (SF), 27 (Horreur), 18 (Drame), 35 (Comédie), 53 (Thriller)
        $genreNames = [
            878 => 'Science-fiction',
            27  => 'Horreur',
            18  => 'Drame',
            35  => 'Comédie',
            53  => 'Thriller',
            14  => 'Fantastique',
            80  => 'Policier',
        ];
        if ($isCulte && !empty($genres)) {
            foreach ($genres as $gid) {
                if (isset($genreNames[$gid])) {
                    $badges[] = [
                        'key'   => 'culte_genre_' . $gid,
                        'label' => "Culte • " . $genreNames[$gid],
                        'icon'  => 'fa-film',
                        'reason' => "Culte dans le genre « " . $genreNames[$gid] . " »"
                    ];
                }
            }
        }

        return $badges;
    }

    /**
     * Version “lite” pour les listes (on n’a pas toujours OMDb ici).
     */
    public function decideBadgesLite(array $tmdb, bool $isSeries): array
    {
        $voteAverage = (float)($tmdb['vote_average'] ?? 0);
        $voteCount   = (int)($tmdb['vote_count'] ?? 0);
        $popularity  = (float)($tmdb['popularity'] ?? 0.0);
        $genres      = (array)($tmdb['genre_ids'] ?? []);
        $year        = $this->getYear($tmdb, $isSeries);

        $badges = [];

        // Chef-d’œuvre (lite) : sans IMDb/RT/MC → seuils TMDb plus stricts
        if ($voteAverage >= 8.4 && $voteCount >= 8000) {
            $badges[] = [
                'key' => 'chef',
                'label' => "Chef-d’œuvre",
                'icon' => 'fa-trophy',
                'reason' => sprintf(
                    "TMDb %.1f/10, %s votes",
                    $voteAverage,
                    number_format($voteCount, 0, '.', ' ')
                )
            ];
        }

        // Culte (lite)
        if ($year !== null && $year <= (int)date('Y') - 10 && $voteCount >= 2000 && $popularity >= 30) {
            $badges[] = [
                'key' => 'culte',
                'label' => 'Film culte',
                'icon' => 'fa-fire',
                'reason' => sprintf(
                    "Année %d, %s votes, popularité %.1f",
                    $year,
                    number_format($voteCount, 0, '.', ' '),
                    $popularity
                )
            ];
        }

        // Classique 80/90
        if (!$isSeries && $year !== null && $year >= 1980 && $year <= 1999) {
            $badges[] = ['key' => 'classic', 'label' => 'Classique 80/90', 'icon' => 'fa-popcorn', 'reason' => "Sorti en {$year}"];
        }

        // Genre culte si culte détecté
        $genreNames = [
            878 => 'Science-fiction',
            27  => 'Horreur',
            18  => 'Drame',
            35  => 'Comédie',
            53  => 'Thriller',
            14  => 'Fantastique',
            80  => 'Policier',
        ];
        $isCulte = array_filter($badges, fn($b) => $b['key'] === 'culte');
        if (!empty($isCulte)) {
            foreach ($genres as $gid) {
                if (isset($genreNames[$gid])) {
                    $badges[] = [
                        'key' => 'culte_genre_' . $gid,
                        'label' => "Culte • " . $genreNames[$gid],
                        'icon' => 'fa-film',
                        'reason' => "Culte dans « " . $genreNames[$gid] . " »"
                    ];
                }
            }
        }

        return $badges;
    }
}
