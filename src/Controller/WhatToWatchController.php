<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Contrôleur pour l'application "What to Watch"
 * Gère l'affichage des films et séries via l'API TMDb
 */
class WhatToWatchController extends AbstractController
{
    private const API_BASE_URL = 'https://api.themoviedb.org/3';
    private const MAX_PAGES = 500;
    private const DEFAULT_PAGE = 1;

    private const CACHE_TTL = 3600;
    private const CACHE_TTL_CONTENT = 7200;
    private const CACHE_TTL_GENRES = 86400;

    private const MIN_VOTE_COUNT_MOVIES = 200;
    private const MIN_VOTE_COUNT_SERIES = 100;

    private const ANIMATION_GENRE_ID = 16;

    private const API_TIMEOUT = 10;
    private const API_RETRY_COUNT = 2;

    private array $movieGenres = [];
    private array $seriesGenres = ['Séries' => 'tv_top_rated'];
    private array $allGenres = [];

    public function __construct(
        private HttpClientInterface $client,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private string $apiKey,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        #[Autowire('%env(OMDB_API_KEY)%')]
        private ?string $omdbApiKey = null
    ) {}


    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $parameters['allGenres'] = $this->getAllGenresLazy();
        return parent::render($view, $parameters, $response);
    }

    #[Route('/', name: 'landing')]
    public function landing(): Response
    {
        return $this->render('what_to_watch/landing.html.twig');
    }

    #[Route('/selection', name: 'home')]
    public function home(): Response
    {
        $user = $this->getUser();

        if ($user) {
            $this->logger->info('Utilisateur connecté', [
                'email' => $user->getUserIdentifier(),
                'roles' => $user->getRoles()
            ]);
        }

        try {
            $movieGenres = $this->getMovieGenres();

            if (empty($movieGenres)) {
                $this->logger->error('Aucun genre de film récupéré depuis l\'API TMDb. Vérifiez votre clé API.');
                throw new \RuntimeException('Impossible de charger les genres de films. Vérifiez la configuration de l\'API.');
            }

            $sorted_categories = array_map(
                fn($name, $id) => ['name' => $name, 'id' => $id],
                array_keys($movieGenres),
                $movieGenres
            );

            $special_categories = [
                ['name' => 'Au cinéma', 'id' => 'cinema'],
                ['name' => 'Séries', 'id' => 'tv_top_rated']
            ];

            return $this->render('what_to_watch/index.html.twig', [
                'title' => 'Faites votre choix',
                'sorted_categories' => $sorted_categories,
                'special_categories' => $special_categories,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du chargement de la page d\'accueil', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    #[Route('/list/{genreId}', name: 'movies_show', requirements: ['genreId' => '[\w_]+'])]
    public function moviesShow(Request $request, string $genreId): Response
    {
        try {
            $isSeries = ($genreId === 'tv_top_rated');
            $params = $this->validateAndGetParams($request, $isSeries);

            $result = $this->fetchContentWithCache($genreId, $params['sortBy'], $params['order'], $params['page']);

            $viewParams = array_merge($result, [
                'genreId'      => $genreId,
                'currentPage'  => $params['page'],
                'currentSort'  => $params['sortBy'],
                'currentOrder' => $params['order'],
                'filters'      => $this->getAvailableFilters($isSeries),
                'pagination'   => $this->buildPagination($params['page'], $result['totalPages']),
            ]);

            return $this->render('what_to_watch/movies.html.twig', $viewParams);
        } catch (BadRequestHttpException | \InvalidArgumentException $e) {
            throw $this->createNotFoundException('Ce genre n\'existe pas.');
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du chargement du contenu', [
                'genre' => $genreId,
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    #[Route('/cinema', name: 'in_theaters')]
    public function inTheaters(Request $request): Response
    {
        try {
            $sortParam = $request->query->get('sort', 'popularity.desc');
            $sortParts = explode('.', $sortParam);

            $validSortOptions = ['popularity', 'release_date', 'vote_average'];
            $sortBy = in_array($sortParts[0], $validSortOptions) ? $sortParts[0] : 'popularity';
            $order = $this->validateOrder($sortParts[1] ?? 'desc');

            $page = $request->query->getInt('page', self::DEFAULT_PAGE);
            if ($page < 1 || $page > self::MAX_PAGES) {
                throw new BadRequestHttpException('Numéro de page invalide');
            }

            $cacheKey = sprintf('in_theaters_%s_%s_%d', $sortBy, $order, $page);
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($sortBy, $order, $page) {
                $item->expiresAfter(self::CACHE_TTL);
                $apiParams = [
                    'sort_by' => "{$sortBy}.{$order}",
                    'page' => $page,
                    'watch_region' => 'FR',
                    'primary_release_date.lte' => date('Y-m-d'),
                    'primary_release_date.gte' => date('Y-m-d', strtotime('-1 month')),
                    'with_release_type' => '3',
                    'vote_count.gte' => 20
                ];
                $data = $this->makeApiRequest('/discover/movie', $apiParams);
                return [
                    'items' => $this->enrichItems($data['results'] ?? [], false),
                    'listTitle' => 'Actuellement au cinéma',
                    'isSeries' => false,
                    'totalPages' => min($data['total_pages'] ?? 1, self::MAX_PAGES),
                    'totalResults' => $data['total_results'] ?? 0,
                ];
            });

            $inTheaterFilters = [
                'sort_options' => [
                    'popularity' => 'Popularité',
                    'release_date' => 'Date de sortie',
                    'vote_average' => 'Note'
                ],
                'order_options' => $this->getAvailableFilters(false)['order_options']
            ];

            $viewParams = array_merge($result, [
                'currentPage' => $page,
                'filters' => $inTheaterFilters,
                'currentSort' => $sortBy,
                'currentOrder' => $order,
                'genreId' => 'cinema',
                'pagination' => $this->buildPagination($page, $result['totalPages']),
            ]);

            return $this->render('what_to_watch/movies.html.twig', $viewParams);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors du chargement des sorties cinéma', [
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        if (strlen($query) < 2) {
            return new JsonResponse(['results' => []]);
        }

        try {
            return new JsonResponse($this->searchContent($query));
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la recherche API', [
                'query' => $query,
                'exception' => $e->getMessage()
            ]);
            return new JsonResponse(['error' => 'Erreur de recherche'], 500);
        }
    }

    #[Route('/search', name: 'search_results')]
    public function searchResultsPage(Request $request): Response
    {
        try {
            $query = $request->query->get('q', '');
            $page = $request->query->getInt('page', self::DEFAULT_PAGE);
            $mediaType = $request->query->get('media_type', 'all');

            if (empty($query)) {
                return $this->redirectToRoute('home');
            }

            $validMediaTypes = ['all', 'movie', 'tv'];
            if (!in_array($mediaType, $validMediaTypes)) {
                $mediaType = 'all';
            }

            $sortParam = $request->query->get('sort', 'popularity.desc');
            $sortParts = explode('.', $sortParam);
            $sortBy = in_array($sortParts[0], ['popularity', 'vote_average', 'release_date'])
                ? $sortParts[0]
                : 'popularity';
            $order = in_array($sortParts[1] ?? 'desc', ['asc', 'desc'])
                ? $sortParts[1]
                : 'desc';

            $cacheKey = 'search_results_' . md5($query . $sortBy . $order . $mediaType) . '_' . $page;

            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($query, $page, $sortBy, $order, $mediaType) {
                $item->expiresAfter(self::CACHE_TTL);
                $data = $this->makeApiRequest('/search/multi', ['query' => $query, 'page' => $page]);

                $filteredResults = array_filter($data['results'] ?? [], function ($result) use ($mediaType) {
                    $hasValidType = isset($result['media_type']) && in_array($result['media_type'], ['movie', 'tv']);
                    $hasPoster = !empty($result['poster_path']);

                    if (!$hasValidType || !$hasPoster) {
                        return false;
                    }

                    if ($mediaType === 'all') {
                        return true;
                    } elseif ($mediaType === 'movie') {
                        return $result['media_type'] === 'movie';
                    } else {
                        return $result['media_type'] === 'tv';
                    }
                });

                $enriched = $this->enrichItems($filteredResults, false);

                usort($enriched, function ($a, $b) use ($sortBy, $order) {
                    $valueA = match ($sortBy) {
                        'vote_average' => $a['vote_average'] ?? 0,
                        'release_date' => $a['release_date'] ?? $a['first_air_date'] ?? '',
                        default => $a['popularity'] ?? 0
                    };
                    $valueB = match ($sortBy) {
                        'vote_average' => $b['vote_average'] ?? 0,
                        'release_date' => $b['release_date'] ?? $b['first_air_date'] ?? '',
                        default => $b['popularity'] ?? 0
                    };

                    $comparison = $valueA <=> $valueB;
                    return $order === 'desc' ? -$comparison : $comparison;
                });

                return [
                    'items' => $enriched,
                    'totalPages' => min($data['total_pages'] ?? 1, self::MAX_PAGES),
                    'totalResults' => $data['total_results'] ?? 0
                ];
            });

            return $this->render('what_to_watch/movies.html.twig', [
                'items' => $result['items'],
                'listTitle' => "Résultats pour : \"{$query}\"",
                'isSeries' => null,
                'totalPages' => $result['totalPages'],
                'totalResults' => $result['totalResults'],
                'currentPage' => $page,
                'currentSort' => $sortBy,
                'currentOrder' => $order,
                'currentMediaType' => $mediaType,
                'filters' => [
                    'sort_options' => [
                        'popularity' => 'Popularité',
                        'vote_average' => 'Note',
                        'release_date' => 'Date de sortie'
                    ],
                    'order_options' => ['desc' => 'Décroissant', 'asc' => 'Croissant'],
                    'media_type_options' => [
                        'all' => 'Tous',
                        'movie' => 'Films',
                        'tv' => 'Séries'
                    ]
                ],
                'pagination' => $this->buildPagination($page, $result['totalPages']),
                'searchQuery' => $query
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la page de recherche', [
                'query' => $query,
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getContentDetail(int $id, bool $isSeries): Response
    {
        try {
            $type = $isSeries ? 'tv' : 'movie';
            $cacheKey = "{$type}_{$id}";

            $item = $this->cache->get($cacheKey, function (ItemInterface $item) use ($id, $type) {

                $item->expiresAfter(self::CACHE_TTL_CONTENT);
                return $this->makeApiRequest("/{$type}/{$id}", [
                    'append_to_response' => 'videos,credits,recommendations,similar'
                ]);
            });

            return $this->render('what_to_watch/content.html.twig', compact('item', 'isSeries'));
        } catch (\Exception $e) {
            $this->logger->error('Contenu introuvable', [
                'type' => $type ?? 'unknown',
                'id' => $id
            ]);
            throw $this->createNotFoundException(
                $isSeries ? 'Cette série est introuvable.' : 'Ce film est introuvable.'
            );
        }
    }

    private function validateAndGetParams(Request $request, bool $isSeries): array
    {
        $sortParam = $request->query->get('sort', 'popularity.desc');
        $sortParts = explode('.', $sortParam);
        $sortBy = $this->validateSortBy($sortParts[0], $isSeries);
        $order = $this->validateOrder($sortParts[1] ?? 'desc');
        $page = $request->query->getInt('page', self::DEFAULT_PAGE);

        if ($page < 1 || $page > self::MAX_PAGES) {
            throw new BadRequestHttpException('Numéro de page invalide');
        }

        return compact('sortBy', 'order', 'page');
    }

    private function getMovieGenres(): array
    {
        if (empty($this->movieGenres)) {
            $this->movieGenres = $this->fetchApiGenres();
            ksort($this->movieGenres);
        }
        return $this->movieGenres;
    }

    private function getAllGenresLazy(): array
    {
        if (empty($this->allGenres)) {
            $movieGenresData = [];
            foreach ($this->getMovieGenres() as $name => $id) {
                $movieGenresData[] = [
                    'name' => $name,
                    'id' => $id,
                    'icon' => $this->getGenreIcon($id)
                ];
            }

            $tvGenresData = [];
            foreach ($this->seriesGenres as $name => $id) {
                $tvGenresData[] = [
                    'name' => $name,
                    'id' => $id,
                    'icon' => $this->getGenreIcon($id)
                ];
            }

            $this->allGenres = [
                'movie_genres' => $movieGenresData,
                'tv_genres' => $tvGenresData
            ];
        }
        return $this->allGenres;
    }

    private function fetchApiGenres(): array
    {
        return $this->cache->get('api_movie_genres', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL_GENRES);

            try {
                $data = $this->makeApiRequest('/genre/movie/list');

                if (empty($data['genres'])) {
                    $this->logger->warning('L\'API TMDb a retourné une réponse vide pour les genres');
                    return [];
                }

                return array_column($data['genres'], 'id', 'name');
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la récupération des genres', [
                    'exception' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    private function validateSortBy(string $sortBy, bool $isSeries): string
    {
        $valid = $isSeries
            ? ['popularity', 'vote_average', 'first_air_date', 'name']
            : ['popularity', 'vote_average', 'release_date', 'original_title', 'revenue'];

        return in_array($sortBy, $valid, true) ? $sortBy : 'popularity';
    }

    private function validateOrder(string $order): string
    {
        return in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
    }

    private function fetchContentWithCache(string $genreId, string $sortBy, string $order, int $page): array
    {
        $cacheKey = sprintf('content_%s_%s_%s_%d', $genreId, $sortBy, $order, $page);
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($genreId, $sortBy, $order, $page) {
            $item->expiresAfter(self::CACHE_TTL);
            return $this->fetchContent($genreId, $sortBy, $order, $page);
        });
    }

    private function fetchContent(string $genreId, string $sortBy, string $order, int $page): array
    {
        return ($genreId === 'tv_top_rated')
            ? $this->fetchSeriesContent($sortBy, $order, $page)
            : $this->fetchMoviesContent($genreId, $sortBy, $order, $page);
    }

    private function fetchSeriesContent(string $sortBy, string $order, int $page): array
    {
        $sortMap = ['name' => 'original_name'];
        $apiSortBy = $sortMap[$sortBy] ?? $sortBy;

        $data = $this->makeApiRequest('/discover/tv', [
            'sort_by' => "{$apiSortBy}.{$order}",
            'page' => $page,
            'vote_count.gte' => self::MIN_VOTE_COUNT_SERIES,
            'watch_region' => 'FR',
            'with_watch_monetization_types' => 'flatrate|free|ads|rent|buy'
        ]);

        return [
            'items' => $this->enrichItems($data['results'] ?? [], true),
            'listTitle' => 'Top des meilleures séries',
            'isSeries' => true,
            'totalPages' => min($data['total_pages'] ?? 1, self::MAX_PAGES),
            'totalResults' => $data['total_results'] ?? 0
        ];
    }

    private function fetchMoviesContent(string $genreId, string $sortBy, string $order, int $page): array
    {
        $movieGenres = $this->getMovieGenres();

        if (!is_numeric($genreId) || !in_array((int)$genreId, $movieGenres, true)) {
            throw new \InvalidArgumentException('Genre de film invalide');
        }

        $params = [
            'with_genres' => $genreId,
            'sort_by' => "{$sortBy}.{$order}",
            'page' => $page,
            'vote_count.gte' => self::MIN_VOTE_COUNT_MOVIES,
            'watch_region' => 'FR',
            'with_watch_monetization_types' => 'flatrate|free|ads|rent|buy',
            'primary_release_date.lte' => date('Y-m-d'),
            'primary_release_date.gte' => (date('Y') - 50) . '-01-01'
        ];

        if ((int)$genreId !== self::ANIMATION_GENRE_ID) {
            $params['without_genres'] = self::ANIMATION_GENRE_ID;
        }

        if ($sortBy === 'revenue') {
            $params['with_revenue.gte'] = 1000000;
        }

        $data = $this->makeApiRequest('/discover/movie', $params);
        $genreName = array_search((int)$genreId, $movieGenres, true);

        return [
            'items' => $this->enrichItems($data['results'] ?? [], false),
            'listTitle' => "Films : {$genreName}",
            'isSeries' => false,
            'totalPages' => min($data['total_pages'] ?? 1, self::MAX_PAGES),
            'totalResults' => $data['total_results'] ?? 0
        ];
    }

    private function makeApiRequest(string $endpoint, array $params = [], int $retry = 0): array
    {
        try {
            $queryParams = array_merge([
                'api_key' => $this->apiKey,
                'language' => 'fr-FR'
            ], $params);

            $response = $this->client->request('GET', self::API_BASE_URL . $endpoint, [
                'query' => $queryParams,
                'timeout' => self::API_TIMEOUT
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->warning('Réponse API TMDb non-200', [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'params' => $params
                ]);
                return [];
            }

            return $response->toArray();
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            if ($retry < self::API_RETRY_COUNT) {
                usleep(500000 * ($retry + 1));
                $this->logger->info('Retry de la requête API', [
                    'endpoint' => $endpoint,
                    'retry' => $retry + 1
                ]);
                return $this->makeApiRequest($endpoint, $params, $retry + 1);
            }

            $this->logger->error('Erreur réseau API TMDb après retries', [
                'endpoint' => $endpoint,
                'exception' => $e->getMessage()
            ]);
            throw new \RuntimeException('Erreur de communication avec l\'API TMDb');
        } catch (\Exception $e) {
            $this->logger->error('Erreur API TMDb', [
                'endpoint' => $endpoint,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Erreur lors de la récupération des données');
        }
    }

    private function enrichItems(array $items, bool $isSeries): array
    {
        return array_map(function ($item) use ($isSeries) {
            $type = $isSeries ? 'tv' : ($item['media_type'] ?? 'movie');
            $date = $item[$type === 'tv' ? 'first_air_date' : 'release_date'] ?? '';
            $item['year'] = $date ? substr($date, 0, 4) : 'N/A';
            $item['isSeries'] = ($type === 'tv');
            return $item;
        }, $items);
    }

    private function buildPagination(int $currentPage, int $totalPages): array
    {
        $range = 2;
        $start = max(1, $currentPage - $range);
        $end = min($totalPages, $currentPage + $range);
        $pages = range($start, $end);

        return compact('currentPage', 'totalPages', 'pages');
    }

    private function getAvailableFilters(bool $isSeries): array
    {
        $sort = $isSeries
            ? [
                'popularity' => 'Popularité',
                'vote_average' => 'Note',
                'first_air_date' => 'Date',
                'name' => 'Nom'
            ]
            : [
                'popularity' => 'Popularité',
                'vote_average' => 'Note',
                'release_date' => 'Date',
                'original_title' => 'Titre',
                'revenue' => 'Revenus'
            ];

        return [
            'sort_options' => $sort,
            'order_options' => ['desc' => 'Décroissant', 'asc' => 'Croissant']
        ];
    }

    private function searchContent(string $query): array
    {
        return $this->cache->get('search_' . md5($query), function (ItemInterface $item) use ($query) {
            $item->expiresAfter(300);

            $data = $this->makeApiRequest('/search/multi', ['query' => $query]);

            $filteredResults = array_filter($data['results'] ?? [], function ($result) {
                return isset($result['media_type'])
                    && in_array($result['media_type'], ['movie', 'tv'])
                    && !empty($result['poster_path']);
            });

            $finalResults = array_slice(array_values($filteredResults), 0, 10);

            return ['results' => $finalResults];
        });
    }

    private function getGenreIcon(string|int $genreId): string
    {
        $icons = [
            28 => 'fa-bomb',
            12 => 'fa-compass',
            16 => 'fa-pencil-ruler',
            35 => 'fa-laugh-beam',
            80 => 'fa-user-secret',
            99 => 'fa-file-video',
            18 => 'fa-theater-masks',
            10751 => 'fa-home',
            14 => 'fa-magic',
            36 => 'fa-history',
            27 => 'fa-ghost',
            10402 => 'fa-music',
            9648 => 'fa-search',
            10749 => 'fa-heart',
            878 => 'fa-robot',
            10770 => 'fa-film',
            53 => 'fa-bolt',
            10752 => 'fa-fighter-jet',
            37 => 'fa-hat-cowboy'
        ];

        if (!is_numeric($genreId)) {
            return match ($genreId) {
                'tv_top_rated' => 'fa-tv',
                'cinema' => 'fa-ticket-alt',
                default => 'fa-film'
            };
        }

        return $icons[$genreId] ?? 'fa-film';
    }
}