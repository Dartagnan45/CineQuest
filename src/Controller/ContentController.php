<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

/**
 * Version 5 - Simple et Efficace
 * - Icônes TMDb/IMDb cliquables vers les sites
 * - Plateformes via TMDb API
 * - Pas de flip (inutile sans verso)
 */
class ContentController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $client,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private string $tmdbApiKey,
        private CacheInterface $cache,
        private LoggerInterface $logger,
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
        try {
            $type = $isSeries ? 'tv' : 'movie';
            $cacheKey = "detail_{$type}_{$id}";

            // --- Récupération des données TMDb ---
            $item = $this->cache->get($cacheKey, function (ItemInterface $cacheItem) use ($id, $type) {
                $cacheItem->expiresAfter(6 * 3600);
                $response = $this->client->request('GET', "https://api.themoviedb.org/3/{$type}/{$id}", [
                    'query' => [
                        'api_key' => $this->tmdbApiKey,
                        'language' => 'fr-FR',
                        'append_to_response' => 'videos,credits,recommendations,similar,external_ids,watch/providers'
                    ]
                ]);
                return $response->toArray();
            });

            // --- Récupération des données OMDb ---
            $omdb = $this->fetchOmdbData($item);

            // --- Extraction des plateformes TMDb ---
            $watchProviders = $this->extractWatchProviders($item);

            // --- Création des liens TMDb et IMDb ---
            $links = $this->generateLinks($item, $type, $id);

            return $this->render('what_to_watch/content.html.twig', [
                'item' => $item,
                'isSeries' => $isSeries,
                'omdb' => $omdb,
                'watchProviders' => $watchProviders,
                'links' => $links,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur détail contenu', [
                'id' => $id,
                'type' => $isSeries ? 'tv' : 'movie',
                'error' => $e->getMessage()
            ]);

            throw $this->createNotFoundException(
                $isSeries ? 'Série introuvable.' : 'Film introuvable.'
            );
        }
    }

    /**
     * Récupère les données OMDb pour avoir la note IMDb
     */
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

            $cacheKey = "omdb_{$imdbId}";
            return $this->cache->get($cacheKey, function (ItemInterface $ci) use ($imdbId) {
                $ci->expiresAfter(24 * 3600);
                $response = $this->client->request('GET', 'https://www.omdbapi.com/', [
                    'query' => [
                        'i' => $imdbId,
                        'apikey' => $this->omdbApiKey,
                        'r' => 'json'
                    ],
                    'timeout' => 8
                ]);
                if ($response->getStatusCode() !== 200) {
                    return null;
                }
                return $response->toArray(false);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Erreur OMDb', [
                'message' => $e->getMessage(),
                'item' => $item['id'] ?? null
            ]);
            return null;
        }
    }

    /**
     * Extrait les plateformes de streaming disponibles en France (TMDb API)
     */
    private function extractWatchProviders(array $item): array
    {
        $providers = [
            'flatrate' => [],
            'rent' => [],
            'buy' => [],
        ];

        if (!isset($item['watch/providers']['results']['FR'])) {
            return $providers;
        }

        $frenchProviders = $item['watch/providers']['results']['FR'];

        // Streaming (abonnement)
        if (isset($frenchProviders['flatrate'])) {
            $providers['flatrate'] = $frenchProviders['flatrate'];
        }

        // Location
        if (isset($frenchProviders['rent'])) {
            $providers['rent'] = $frenchProviders['rent'];
        }

        // Achat
        if (isset($frenchProviders['buy'])) {
            $providers['buy'] = $frenchProviders['buy'];
        }

        return $providers;
    }

    /**
     * Génère les liens vers TMDb et IMDb
     */
    private function generateLinks(array $item, string $type, int $id): array
    {
        $links = [
            'tmdb' => null,
            'imdb' => null,
        ];

        // Lien TMDb
        $links['tmdb'] = "https://www.themoviedb.org/{$type}/{$id}";

        // Lien IMDb
        $imdbId = $item['imdb_id'] ?? ($item['external_ids']['imdb_id'] ?? null);
        if ($imdbId) {
            $links['imdb'] = "https://www.imdb.com/title/{$imdbId}/";
        }

        return $links;
    }

    /**
     * Page de détails d'une personne (acteur/réalisateur)
     */
    #[Route('/person/{id}', name: 'person_details', requirements: ['id' => '\d+'])]
    public function personDetails(int $id): Response
    {
        try {
            $cacheKey = "person_{$id}";

            $person = $this->cache->get($cacheKey, function (ItemInterface $cacheItem) use ($id) {
                $cacheItem->expiresAfter(24 * 3600);

                $response = $this->client->request('GET', "https://api.themoviedb.org/3/person/{$id}", [
                    'query' => [
                        'api_key' => $this->tmdbApiKey,
                        'language' => 'fr-FR',
                        'append_to_response' => 'movie_credits,tv_credits,images'
                    ]
                ]);

                return $response->toArray();
            });

            // Trier les films par date
            $movieCredits = $person['movie_credits']['cast'] ?? [];
            $movieCrew = $person['movie_credits']['crew'] ?? [];

            // Films réalisés
            $directedMovies = array_filter($movieCrew, function ($credit) {
                return $credit['job'] === 'Director';
            });

            usort($movieCredits, function ($a, $b) {
                $dateA = $a['release_date'] ?? '1900-01-01';
                $dateB = $b['release_date'] ?? '1900-01-01';
                return $dateB <=> $dateA;
            });

            usort($directedMovies, function ($a, $b) {
                $dateA = $a['release_date'] ?? '1900-01-01';
                $dateB = $b['release_date'] ?? '1900-01-01';
                return $dateB <=> $dateA;
            });

            $tvCredits = $person['tv_credits']['cast'] ?? [];
            usort($tvCredits, function ($a, $b) {
                $dateA = $a['first_air_date'] ?? '1900-01-01';
                $dateB = $b['first_air_date'] ?? '1900-01-01';
                return $dateB <=> $dateA;
            });

            return $this->render('what_to_watch/person.html.twig', [
                'person' => $person,
                'movieCredits' => $movieCredits,
                'directedMovies' => $directedMovies,
                'tvCredits' => $tvCredits,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur détail personne', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            throw $this->createNotFoundException('Personne introuvable.');
        }
    }
}