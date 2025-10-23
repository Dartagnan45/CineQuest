<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SecurityController extends AbstractController
{
    private const API_BASE_URL = 'https://api.themoviedb.org/3';
    private const CACHE_TTL_GENRES = 86400;

    public function __construct(
        private HttpClientInterface $client,
        private CacheInterface $cache,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private string $apiKey
    ) {}

    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        $parameters['allGenres'] = $this->getAllGenres();
        return parent::render($view, $parameters, $response);
    }

    private function getAllGenres(): array
    {
        return $this->cache->get('all_genres_menu', function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL_GENRES);
            $movieGenresData = $this->makeApiRequest('/genre/movie/list');
            $movieGenres = array_map(fn($g) => [
                'name' => $g['name'],
                'id' => $g['id'],
                'icon' => $this->getGenreIcon($g['id'])
            ], $movieGenresData['genres'] ?? []);
            usort($movieGenres, fn($a, $b) => $a['name'] <=> $b['name']);
            return [
                'movie_genres' => $movieGenres,
                'tv_genres' => [['name' => 'Séries', 'id' => 'tv_top_rated', 'icon' => 'fa-tv']]
            ];
        });
    }

    private function makeApiRequest(string $endpoint): array
    {
        try {
            $response = $this->client->request('GET', self::API_BASE_URL . $endpoint, [
                'query' => ['api_key' => $this->apiKey, 'language' => 'fr-FR'],
            ]);
            return $response->getStatusCode() === 200 ? $response->toArray() : [];
        } catch (\Exception) {
            return [];
        }
    }

    private function getGenreIcon(int $genreId): string
    {
        return match ($genreId) {
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
            37 => 'fa-hat-cowboy',
            default => 'fa-film'
        };
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}