<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

/**
 * Injecte les genres globalement dans Twig pour la navbar
 */
class GenresSubscriber implements EventSubscriberInterface
{
    private const API_BASE_URL = 'https://api.themoviedb.org/3';
    private const CACHE_TTL_GENRES = 86400; // 24 heures

    public function __construct(
        private HttpClientInterface $client,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private string $apiKey,
        private CacheInterface $cache,
        private Environment $twig,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        // Ne pas exécuter pour les sous-requêtes
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            // Récupérer les genres et les injecter globalement dans Twig
            $allGenres = $this->getAllGenres();
            $this->twig->addGlobal('allGenres', $allGenres);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des genres', [
                'error' => $e->getMessage()
            ]);
            // En cas d'erreur, on injecte un tableau vide pour éviter les erreurs Twig
            $this->twig->addGlobal('allGenres', [
                'movie_genres' => [],
                'tv_genres' => []
            ]);
        }
    }

    private function getAllGenres(): array
    {
        return $this->cache->get('global_all_genres', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL_GENRES);

            try {
                // Récupération des genres de films
                $movieGenresData = $this->makeApiRequest('/genre/movie/list');
                $movieGenres = [];

                foreach ($movieGenresData['genres'] ?? [] as $genre) {
                    $movieGenres[] = [
                        'id' => $genre['id'],
                        'name' => $genre['name'],
                        'icon' => $this->getGenreIcon($genre['id'])
                    ];
                }

                // Catégorie spéciale : Séries TV
                $tvGenres = [
                    [
                        'id' => 'tv_top_rated',
                        'name' => 'Séries TV',
                        'icon' => 'fa-tv'
                    ]
                ];

                return [
                    'movie_genres' => $movieGenres,
                    'tv_genres' => $tvGenres
                ];
            } catch (\Exception $e) {
                $this->logger->error('Erreur API TMDb genres', [
                    'error' => $e->getMessage()
                ]);
                return [
                    'movie_genres' => [],
                    'tv_genres' => []
                ];
            }
        });
    }

    private function makeApiRequest(string $endpoint): array
    {
        try {
            $response = $this->client->request('GET', self::API_BASE_URL . $endpoint, [
                'query' => [
                    'api_key' => $this->apiKey,
                    'language' => 'fr-FR'
                ],
                'timeout' => 10
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Erreur requête API genres', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function getGenreIcon(int $genreId): string
    {
        $icons = [
            28 => 'fa-bomb',           // Action
            12 => 'fa-compass',        // Aventure
            16 => 'fa-pencil-ruler',   // Animation
            35 => 'fa-laugh-beam',     // Comédie
            80 => 'fa-user-secret',    // Crime
            99 => 'fa-file-video',     // Documentaire
            18 => 'fa-theater-masks',  // Drame
            10751 => 'fa-home',        // Familial
            14 => 'fa-magic',          // Fantastique
            36 => 'fa-history',        // Histoire
            27 => 'fa-ghost',          // Horreur
            10402 => 'fa-music',       // Musique
            9648 => 'fa-search',       // Mystère
            10749 => 'fa-heart',       // Romance
            878 => 'fa-robot',         // Science-Fiction
            10770 => 'fa-film',        // Téléfilm
            53 => 'fa-bolt',           // Thriller
            10752 => 'fa-fighter-jet', // Guerre
            37 => 'fa-hat-cowboy'      // Western
        ];

        return $icons[$genreId] ?? 'fa-film';
    }
}