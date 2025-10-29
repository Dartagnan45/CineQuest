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

    private function renderContentDetail(int $id, bool $isSeries): Response
    {
        $type = $isSeries ? 'tv' : 'movie';

        try {
            // 🔍 LOG: Début du processus
            $this->logger->info('🎬 Début renderContentDetail', [
                'id' => $id,
                'type' => $type,
                'isSeries' => $isSeries
            ]);

            /* 1) TMDb : item + extras */
            $item = $this->cache->get("detail_{$type}_{$id}", function (ItemInterface $ci) use ($type, $id) {
                $ci->expiresAfter(6 * 3600);

                $url = sprintf('%s/%s/%d', self::TMDB_BASE, $type, $id);

                // 🔍 LOG: URL et paramètres
                $this->logger->info('📡 Appel API TMDb', [
                    'url' => $url,
                    'api_key_present' => !empty($this->tmdbApiKey),
                    'api_key_length' => strlen($this->tmdbApiKey)
                ]);

                $resp = $this->client->request('GET', $url, [
                    'query' => [
                        'api_key'            => $this->tmdbApiKey,
                        'language'           => self::LANGUAGE,
                        'append_to_response' => 'videos,credits,recommendations,similar,external_ids,watch_providers'
                    ],
                    'timeout' => 10
                ]);

                $statusCode = $resp->getStatusCode();

                // 🔍 LOG: Réponse HTTP
                $this->logger->info('✅ Réponse TMDb', [
                    'status' => $statusCode,
                    'headers' => $resp->getHeaders(false)
                ]);

                if ($statusCode !== 200) {
                    throw new \RuntimeException("TMDb returned HTTP {$statusCode}");
                }

                $data = $resp->toArray();

                // 🔍 LOG: Structure des données
                $this->logger->info('📦 Données reçues', [
                    'has_watch_providers' => isset($data['watch_providers']),
                    'keys' => array_keys($data)
                ]);

                return $data;
            });

            // 🔍 LOG: Après récupération
            $this->logger->info('✅ Item récupéré', [
                'title' => $item['title'] ?? $item['name'] ?? 'N/A'
            ]);

            /* 2) Plateformes */
            $watchProviders = $this->extractWatchProviders($item);

            $this->logger->info('📺 Watch Providers', [
                'flatrate_count' => count($watchProviders['flatrate']),
                'rent_count' => count($watchProviders['rent']),
                'buy_count' => count($watchProviders['buy'])
            ]);

            /* 3) OMDb */
            $omdb = $this->fetchOmdbData($item);

            /* 4) Liens externes */
            $links = $this->generateLinks($item, $type, $id);

            /* 5) Badges */
            $badges = $this->movieBadgeService->decideBadges($item, $omdb ?? null, $isSeries);

            /* 6) Détection cinéma */
            $isNowPlaying = $this->isCurrentlyInTheaters($item, $isSeries);

            // 🔍 LOG: Avant rendu
            $this->logger->info('🎨 Préparation rendu template', [
                'template' => 'what_to_watch/content.html.twig',
                'has_omdb' => $omdb !== null,
                'badge_count' => count($badges)
            ]);

            /* 7) Rendu */
            return $this->render('what_to_watch/content.html.twig', [
                'item'           => $item,
                'isSeries'       => $isSeries,
                'omdb'           => $omdb,
                'watchProviders' => $watchProviders,
                'links'          => $links,
                'badges'         => $badges,
                'isNowPlaying'   => $isNowPlaying,
            ]);
        } catch (\Throwable $e) {
            // 🔍 LOG: Erreur détaillée
            $this->logger->error('❌ ERREUR COMPLÈTE', [
                'id'         => $id,
                'type'       => $type,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => $e->getTraceAsString()
            ]);

            throw $this->createNotFoundException($isSeries ? 'Série introuvable.' : 'Film introuvable.');
        }
    }

    private function isCurrentlyInTheaters(array $item, bool $isSeries): bool
    {
        if ($isSeries) {
            return false;
        }

        if (!isset($item['release_date']) || !$item['release_date']) {
            return false;
        }

        try {
            $releaseDate = new \DateTimeImmutable($item['release_date']);
            $now = new \DateTimeImmutable();

            if ($releaseDate > $now) {
                return false;
            }

            $daysSinceRelease = $now->diff($releaseDate)->days;

            return $daysSinceRelease < 60;
        } catch (\Exception $e) {
            $this->logger->warning('Erreur parsing release_date', [
                'release_date' => $item['release_date'],
                'error'        => $e->getMessage()
            ]);
            return false;
        }
    }

    private function fetchOmdbData(array $item): ?array
    {
        try {
            if (!$this->omdbApiKey) {
                return null;
            }

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

                $data['imdbRatingFloat'] = null;
                if (!empty($data['imdbRating']) && $data['imdbRating'] !== 'N/A') {
                    $data['imdbRatingFloat'] = (float)$data['imdbRating'];
                }

                $data['rottenTomatoesPercent'] = null;
                $data['metacriticScoreInt']    = null;

                if (!empty($data['Ratings']) && is_array($data['Ratings'])) {
                    foreach ($data['Ratings'] as $r) {
                        if (($r['Source'] ?? null) === 'Rotten Tomatoes' && !empty($r['Value'])) {
                            $data['rottenTomatoesPercent'] = (float)str_replace('%', '', $r['Value']);
                        }
                        if (($r['Source'] ?? null) === 'Metacritic' && !empty($r['Value'])) {
                            $txt = $r['Value'];
                            if (strpos($txt, '/') !== false) {
                                $parts = explode('/', $txt, 2);
                                $data['metacriticScoreInt'] = (float)$parts[0];
                            }
                        }
                    }
                }

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

    private function extractWatchProviders(array $item): array
    {
        $providers = [
            'flatrate' => [],
            'rent'     => [],
            'buy'      => [],
        ];

        // 🔍 LOG: Vérification structure
        $this->logger->debug('🔍 Structure watch_providers', [
            'has_watch_providers' => isset($item['watch_providers']),
            'has_results' => isset($item['watch_providers']['results']),
            'has_FR' => isset($item['watch_providers']['results'][self::REGION])
        ]);

        $root = $item['watch_providers']['results'][self::REGION] ?? null;
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

    private function generateLinks(array $item, string $type, int $id): array
    {
        $links = [
            'tmdb'            => null,
            'imdb'            => null,
            'rotten_tomatoes' => null,
            'metacritic'      => null,
        ];

        $links['tmdb'] = sprintf('https://www.themoviedb.org/%s/%d', $type, $id);

        $imdbId = $item['imdb_id'] ?? ($item['external_ids']['imdb_id'] ?? null);
        if ($imdbId) {
            $links['imdb'] = 'https://www.imdb.com/title/' . $imdbId . '/';
        }

        $title = $type === 'movie' ? ($item['title'] ?? '') : ($item['name'] ?? '');
        if ($title) {
            $rtSlug   = $this->slugWithUnderscores($title);
            $rtPrefix = $type === 'movie' ? 'm' : 'tv';
            $links['rotten_tomatoes'] = sprintf('https://www.rottentomatoes.com/%s/%s', $rtPrefix, $rtSlug);

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

            $movieCredits = $person['movie_credits']['cast'] ?? [];
            $movieCrew    = $person['movie_credits']['crew'] ?? [];
            $tvCredits    = $person['tv_credits']['cast'] ?? [];

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
