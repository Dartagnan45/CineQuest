<?php

namespace App\Controller;

use App\Service\MovieBadgeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ContentController
 * v7 — Badges + OMDb (IMDb/RT/Metacritic) + Plateformes TMDb + Person details
 *
 * - Intègre MovieBadgeService (badges Chef-d’œuvre / Culte / Classiques 80/90 / Culte par genre)
 * - Conserve le caching TMDb/OMDb/Person
 * - Expose links (TMDb/IMDb/Rotten/Metacritic) + watchProviders (FR)
 * - Compatible avec content.html.twig (notes + plateformes + badges) et person.html.twig
 */
class ContentController extends AbstractController
{
    private const TMDB_BASE = 'https://api.themoviedb.org/3';
    private const OMDB_BASE = 'https://www.omdbapi.com/';
    private const REGION     = 'FR';
    private const LANGUAGE   = 'fr-FR';

    public function __construct(
        private HttpClientInterface $client,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private string $tmdbApiKey,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private MovieBadgeService $movieBadgeService,
        #[Autowire('%env(OMDB_API_KEY)%')]
        private ?string $omdbApiKey = null
    ) {}

    /* ===========================
     * ROUTES CONTENU (FILM / TV)
     * =========================== */

    #[Route('/movie/{id}', name: 'movie_content', requirements: ['id' => '\d+'])]
    public function movieDetail(int $id): Response
    {
        return $this->renderContentDetail($id, false);
    }

    #[Route('/tv/{id}', name: 'tv_content', requirements: ['id' => '\d+'])]
    public function tvDetail(int $id): Response
    {
        return $this->renderContentDetail($id, true);
    }

    /**
     * Récupère et rend la page de détail (film/série)
     */
    private function renderContentDetail(int $id, bool $isSeries): Response
    {
        $type = $isSeries ? 'tv' : 'movie';

        try {
            /* 1) TMDb : item + extras (videos, credits, recommendations, similar, external_ids, watch/providers) */
            $item = $this->cache->get("detail_{$type}_{$id}", function (ItemInterface $ci) use ($type, $id) {
                $ci->expiresAfter(6 * 3600);
                $resp = $this->client->request('GET', sprintf('%s/%s/%d', self::TMDB_BASE, $type, $id), [
                    'query' => [
                        'api_key'            => $this->tmdbApiKey,
                        'language'           => self::LANGUAGE,
                        'append_to_response' => 'videos,credits,recommendations,similar,external_ids,watch/providers'
                    ],
                    'timeout' => 10
                ]);
                return $resp->toArray();
            });

            /* 2) Plateformes de visionnage (FR) */
            $watchProviders = $this->extractWatchProviders($item);

            /* 3) OMDb : IMDb + Rotten Tomatoes + Metacritic */
            $omdb = $this->fetchOmdbData($item);

            /* 4) Liens externes (TMDb / IMDb / Rotten / Metacritic) */
            $links = $this->generateLinks($item, $type, $id);

            /* 5) Badges (algorithme puissant) */
            $badges = $this->movieBadgeService->decideBadges($item, $omdb ?? null, $isSeries);

            /* 6) Rendu */
            return $this->render('what_to_watch/content.html.twig', [
                'item'           => $item,
                'isSeries'       => $isSeries,
                'omdb'           => $omdb,
                'watchProviders' => $watchProviders,
                'links'          => $links,
                'badges'         => $badges,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur détail contenu', [
                'id'    => $id,
                'type'  => $type,
                'error' => $e->getMessage()
            ]);

            throw $this->createNotFoundException($isSeries ? 'Série introuvable.' : 'Film introuvable.');
        }
    }

    /* ===========================
     * OMDb (IMDb / RT / Metacritic)
     * =========================== */

    /**
     * Récupère les données OMDb : IMDb, Rotten Tomatoes, Metacritic.
     * Ajoute des champs normalisés utiles en Twig :
     * - imdbRatingFloat
     * - rottenTomatoesPercent
     * - metacriticScoreInt
     */
    private function fetchOmdbData(array $item): ?array
    {
        try {
            if (!$this->omdbApiKey) {
                return null;
            }

            // imdb_id directement ou via external_ids
            $imdbId = $item['imdb_id'] ?? ($item['external_ids']['imdb_id'] ?? null);
            if (!$imdbId) {
                return null;
            }

            return $this->cache->get("omdb_{$imdbId}", function (ItemInterface $ci) use ($imdbId) {
                $ci->expiresAfter(24 * 3600);
                $resp = $this->client->request('GET', self::OMDB_BASE, [
                    'query' => [
                        'i'      => $imdbId,
                        'apikey' => $this->omdbApiKey,
                        'r'      => 'json'
                    ],
                    'timeout' => 8
                ]);

                if ($resp->getStatusCode() !== 200) {
                    return null;
                }

                $data = $resp->toArray(false);

                // Normalisation des scores
                $data['imdbRatingFloat'] = null;
                if (!empty($data['imdbRating']) && $data['imdbRating'] !== 'N/A') {
                    $data['imdbRatingFloat'] = (float)$data['imdbRating'];
                }

                $data['rottenTomatoesPercent'] = null;
                $data['metacriticScoreInt']    = null;

                if (!empty($data['Ratings']) && is_array($data['Ratings'])) {
                    foreach ($data['Ratings'] as $r) {
                        if (($r['Source'] ?? null) === 'Rotten Tomatoes' && !empty($r['Value'])) {
                            // "88%" => 88
                            $data['rottenTomatoesPercent'] = (float)str_replace('%', '', $r['Value']);
                        }
                        if (($r['Source'] ?? null) === 'Metacritic' && !empty($r['Value'])) {
                            // "73/100" => 73
                            $txt = $r['Value'];
                            if (strpos($txt, '/') !== false) {
                                $parts = explode('/', $txt, 2);
                                $data['metacriticScoreInt'] = (float)$parts[0];
                            }
                        }
                    }
                }

                // Ajout de champs bruts facilitant Twig si présents autrement
                if (!isset($data['MetacriticScore']) && $data['metacriticScoreInt'] !== null) {
                    $data['MetacriticScore'] = $data['metacriticScoreInt'] . '/100';
                }
                if (!isset($data['RottenTomatoesScore']) && $data['rottenTomatoesPercent'] !== null) {
                    $data['RottenTomatoesScore'] = $data['rottenTomatoesPercent'] . '%';
                }

                return $data;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Erreur OMDb', [
                'message' => $e->getMessage(),
                'item_id' => $item['id'] ?? null
            ]);
            return null;
        }
    }

    /* ===========================
     * PLATEFORMES TMDb (FR)
     * =========================== */

    /**
     * Extrait les plateformes (FR) : flatrate / rent / buy
     */
    private function extractWatchProviders(array $item): array
    {
        $providers = [
            'flatrate' => [],
            'rent'     => [],
            'buy'      => [],
        ];

        $root = $item['watch/providers']['results'][self::REGION] ?? null;
        if (!$root) {
            return $providers;
        }

        if (!empty($root['flatrate'])) {
            $providers['flatrate'] = $root['flatrate'];
        }
        if (!empty($root['rent'])) {
            $providers['rent']     = $root['rent'];
        }
        if (!empty($root['buy'])) {
            $providers['buy']      = $root['buy'];
        }

        return $providers;
    }

    /* ===========================
     * LIENS EXTERNES
     * =========================== */

    /**
     * Génère les liens TMDb/IMDb/Rotten/Metacritic
     */
    private function generateLinks(array $item, string $type, int $id): array
    {
        $links = [
            'tmdb'            => null,
            'imdb'            => null,
            'rotten_tomatoes' => null,
            'metacritic'      => null,
        ];

        // TMDb
        $links['tmdb'] = sprintf('https://www.themoviedb.org/%s/%d', $type, $id);

        // IMDb
        $imdbId = $item['imdb_id'] ?? ($item['external_ids']['imdb_id'] ?? null);
        if ($imdbId) {
            $links['imdb'] = 'https://www.imdb.com/title/' . $imdbId . '/';
        }

        // Rotten Tomatoes / Metacritic : on tente une heuristique slug
        $title = $type === 'movie' ? ($item['title'] ?? '') : ($item['name'] ?? '');
        if ($title) {
            // Rotten = underscores
            $rtSlug   = $this->slugWithUnderscores($title);
            $rtPrefix = $type === 'movie' ? 'm' : 'tv';
            $links['rotten_tomatoes'] = sprintf('https://www.rottentomatoes.com/%s/%s', $rtPrefix, $rtSlug);

            // Metacritic = dashes
            $mcSlug = $this->slugWithDashes($title);
            $links['metacritic'] = sprintf('https://www.metacritic.com/%s/%s', $type, $mcSlug);
        }

        return $links;
    }

    private function slugWithUnderscores(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        $slug = preg_replace('/_+/', '_', $slug);
        return $slug ?: 'titre';
    }

    private function slugWithDashes(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug ?: 'titre';
    }

    /* ===========================
     * PERSONNES (Acteurs/Réalisateurs)
     * =========================== */

    #[Route('/person/{id}', name: 'person_details', requirements: ['id' => '\d+'])]
    public function personDetails(int $id): Response
    {
        try {
            $person = $this->cache->get("person_{$id}", function (ItemInterface $ci) use ($id) {
                $ci->expiresAfter(24 * 3600);
                $resp = $this->client->request('GET', sprintf('%s/person/%d', self::TMDB_BASE, $id), [
                    'query' => [
                        'api_key'            => $this->tmdbApiKey,
                        'language'           => self::LANGUAGE,
                        'append_to_response' => 'movie_credits,tv_credits,images'
                    ],
                    'timeout' => 10
                ]);
                return $resp->toArray();
            });

            // Filmographie – tri par dates
            $movieCredits = $person['movie_credits']['cast'] ?? [];
            $movieCrew    = $person['movie_credits']['crew'] ?? [];
            $tvCredits    = $person['tv_credits']['cast'] ?? [];

            // Réalisations
            $directedMovies = array_filter($movieCrew, fn($c) => ($c['job'] ?? null) === 'Director');

            usort($movieCredits, function ($a, $b) {
                $da = $a['release_date'] ?? '1900-01-01';
                $db = $b['release_date'] ?? '1900-01-01';
                return $db <=> $da;
            });

            usort($directedMovies, function ($a, $b) {
                $da = $a['release_date'] ?? '1900-01-01';
                $db = $b['release_date'] ?? '1900-01-01';
                return $db <=> $da;
            });

            usort($tvCredits, function ($a, $b) {
                $da = $a['first_air_date'] ?? '1900-01-01';
                $db = $b['first_air_date'] ?? '1900-01-01';
                return $db <=> $da;
            });

            return $this->render('what_to_watch/person.html.twig', [
                'person'         => $person,
                'movieCredits'   => $movieCredits,
                'directedMovies' => $directedMovies,
                'tvCredits'      => $tvCredits,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur détail personne', [
                'id'    => $id,
                'error' => $e->getMessage()
            ]);
            throw $this->createNotFoundException('Personne introuvable.');
        }
    }
}
