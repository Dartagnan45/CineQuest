<?php
// src/Controller/MovieListController.php

namespace App\Controller;

use App\Entity\MovieList;
use App\Entity\MovieListItem;
use App\Form\MovieListType;
use App\Repository\MovieListItemRepository;
use App\Repository\MovieListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[Route('/mes-listes')]
#[IsGranted('ROLE_USER')]
class MovieListController extends AbstractController
{
    private const API_BASE_URL = 'https://api.themoviedb.org/3';
    private const CACHE_TTL_CONTENT = 3600; // 1 heure
    private const API_TIMEOUT = 5;
    private const MAX_PARALLEL_REQUESTS = 10; // Limite de requêtes parallèles

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire('%env(THE_MOVIE_DB_API_KEY)%')]
        private readonly string $apiKey,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly MovieListRepository $movieListRepository
    ) {}

    /**
     * Liste toutes les listes de films de l'utilisateur connecté
     */
    #[Route('/', name: 'app_movie_list_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Récupère les listes avec le nombre d'items (optimisé avec une seule requête)
        $movieLists = $this->movieListRepository->findByUserWithItemCount($user);

        return $this->render('movie_list/index.html.twig', [
            'movie_lists' => $movieLists,
        ]);
    }

    /**
     * Crée une nouvelle liste de films
     */
    #[Route('/creer', name: 'app_movie_list_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $movieList = new MovieList();
        $form = $this->createForm(MovieListType::class, $movieList);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            // Vérifie que l'utilisateur n'a pas déjà trop de listes (limite à 50)
            $existingListsCount = $this->movieListRepository->count(['user' => $user]);
            if ($existingListsCount >= 50) {
                $this->addFlash('error', 'Vous avez atteint la limite de 50 listes. Supprimez-en une pour en créer une nouvelle.');
                return $this->redirectToRoute('app_movie_list_index');
            }

            $movieList->setUser($user);
            $movieList->setCreatedAt(new \DateTimeImmutable());

            try {
                $entityManager->persist($movieList);
                $entityManager->flush();

                $this->addFlash('success', sprintf('Votre liste "%s" a été créée avec succès !', $movieList->getName()));
                return $this->redirectToRoute('app_movie_list_show', ['id' => $movieList->getId()], Response::HTTP_SEE_OTHER);
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la création de la liste', [
                    'user_id' => $user->getId(),
                    'exception' => $e->getMessage()
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors de la création de la liste.');
            }
        }

        return $this->render('movie_list/new.html.twig', [
            'movie_list' => $movieList,
            'form' => $form,
        ]);
    }

    /**
     * Toggle un item dans la liste "Favoris" de l'utilisateur
     */
    #[Route('/favoris/toggle', name: 'app_movie_list_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(
        Request $request,
        EntityManagerInterface $entityManager,
        MovieListItemRepository $movieListItemRepository
    ): JsonResponse {
        // Récupérer les données JSON de la requête
        $data = json_decode($request->getContent(), true);
        $tmdbId = $data['tmdbId'] ?? null;
        $tmdbType = $data['tmdbType'] ?? null;

        if (!$tmdbId || !$tmdbType) {
            return new JsonResponse(['error' => 'Données invalides'], Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Trouver ou créer la liste "Favoris"
        $favoritesList = $this->getOrCreateFavoritesList($user, $entityManager);

        // Vérifier si l'item existe déjà dans les favoris
        $existingItem = $movieListItemRepository->findOneBy([
            'movieList' => $favoritesList,
            'tmdbId' => $tmdbId,
            'tmdbType' => $tmdbType
        ]);

        if ($existingItem) {
            // L'item existe déjà, on le supprime
            $entityManager->remove($existingItem);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'isFavorite' => false,
                'message' => 'Retiré des favoris'
            ]);
        } else {
            // L'item n'existe pas, on l'ajoute
            $newItem = new MovieListItem();
            $newItem->setMovieList($favoritesList);
            $newItem->setTmdbId($tmdbId);
            $newItem->setTmdbType($tmdbType);
            $newItem->setAddedAt(new \DateTimeImmutable());

            $entityManager->persist($newItem);
            $entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'isFavorite' => true,
                'message' => 'Ajouté aux favoris'
            ]);
        }
    }

    /**
     * Ajoute un film/série à une liste (AJAX)
     * SÉCURISÉ : Vérifie l'ownership avant le chargement de l'entité
     */
    #[Route('/{id}/add/{tmdbType}/{tmdbId}', name: 'app_movie_list_add_item', requirements: ['id' => '\d+', 'tmdbId' => '\d+', 'tmdbType' => 'movie|tv'], methods: ['GET'])]
    public function addItem(
        int $id,
        string $tmdbType,
        int $tmdbId,
        EntityManagerInterface $entityManager,
        MovieListItemRepository $movieListItemRepository
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // SÉCURITÉ : Charge uniquement les listes de l'utilisateur
        $movieList = $this->movieListRepository->findOneBy([
            'id' => $id,
            'user' => $user
        ]);

        if (!$movieList) {
            return new JsonResponse(
                ['message' => 'Liste introuvable ou accès non autorisé'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Vérifie si l'item existe déjà dans cette liste
        if ($movieListItemRepository->alreadyExists($movieList, $tmdbId, $tmdbType)) {
            return new JsonResponse(
                ['message' => 'Cet élément est déjà dans la liste'],
                Response::HTTP_CONFLICT
            );
        }

        // Limite le nombre d'items par liste (par exemple 500)
        $currentItemsCount = $movieListItemRepository->count(['movieList' => $movieList]);
        if ($currentItemsCount >= 500) {
            return new JsonResponse(
                ['message' => 'Cette liste a atteint sa limite de 500 éléments'],
                Response::HTTP_FORBIDDEN
            );
        }

        try {
            $item = new MovieListItem();
            $item->setMovieList($movieList);
            $item->setTmdbId($tmdbId);
            $item->setTmdbType($tmdbType);
            $item->setAddedAt(new \DateTimeImmutable());

            $entityManager->persist($item);
            $entityManager->flush();

            $this->logger->info('Item ajouté à la liste', [
                'user_id' => $user->getId(),
                'list_id' => $movieList->getId(),
                'tmdb_id' => $tmdbId,
                'tmdb_type' => $tmdbType
            ]);

            return new JsonResponse([
                'message' => 'Ajouté avec succès !',
                'list_name' => $movieList->getName()
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de l\'ajout à la liste', [
                'user_id' => $user->getId(),
                'list_id' => $id,
                'exception' => $e->getMessage()
            ]);

            return new JsonResponse(
                ['message' => 'Une erreur est survenue lors de l\'ajout'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Vérifie dans quelles listes un item est présent
     * Renvoie les détails complets pour permettre le toggle
     */
    #[Route('/check-item/{tmdbType}/{tmdbId}', name: 'app_movie_list_check_item', requirements: ['tmdbId' => '\d+', 'tmdbType' => 'movie|tv'], methods: ['GET'])]
    public function checkItem(
        string $tmdbType,
        int $tmdbId,
        MovieListItemRepository $movieListItemRepository
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Récupère tous les items correspondant à cet ID et type pour cet utilisateur
        $items = $movieListItemRepository->createQueryBuilder('item')
            ->join('item.movieList', 'list')
            ->where('list.user = :user')
            ->andWhere('item.tmdbId = :tmdbId')
            ->andWhere('item.tmdbType = :tmdbType')
            ->setParameter('user', $user)
            ->setParameter('tmdbId', $tmdbId)
            ->setParameter('tmdbType', $tmdbType)
            ->getQuery()
            ->getResult();

        // Extrait les noms des listes
        $listNames = [];
        $itemsDetails = [];

        foreach ($items as $item) {
            $listName = $item->getMovieList()->getName();
            $listNames[] = $listName;

            // Ajoute les détails pour chaque liste
            $itemsDetails[] = [
                'listName' => $listName,
                'itemId' => $item->getId(),
                'csrfToken' => $this->container->get('security.csrf.token_manager')
                    ->getToken('delete' . $item->getId())->getValue()
            ];
        }

        return new JsonResponse([
            'lists' => $listNames,
            'items' => $itemsDetails,
            'count' => count($listNames)
        ]);
    }

    /**
     * Affiche le contenu d'une liste avec tous les détails des films/séries
     * OPTIMISÉ : Requêtes parallélisées et mises en cache
     */
    #[Route('/{id}', name: 'app_movie_list_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // SÉCURITÉ : Charge uniquement les listes de l'utilisateur
        $movieList = $this->movieListRepository->findOneBy([
            'id' => $id,
            'user' => $user
        ]);

        if (!$movieList) {
            throw $this->createAccessDeniedException('Cette liste n\'existe pas ou vous n\'y avez pas accès.');
        }

        // Récupère les items de la liste
        $items = $movieList->getMovieListItems()->toArray();

        if (empty($items)) {
            return $this->render('movie_list/show.html.twig', [
                'movie_list' => $movieList,
                'items' => [],
            ]);
        }

        // Groupe les items par type pour optimiser les requêtes
        $movieIds = [];
        $tvIds = [];
        $itemsMap = []; // Pour retrouver facilement les items originaux

        foreach ($items as $item) {
            $itemsMap[$item->getTmdbType() . '_' . $item->getTmdbId()] = $item;

            if ($item->getTmdbType() === 'movie') {
                $movieIds[] = $item->getTmdbId();
            } else {
                $tvIds[] = $item->getTmdbId();
            }
        }

        // Récupère les détails en parallèle avec mise en cache
        $itemsDetails = [];

        if (!empty($movieIds)) {
            $movieDetails = $this->fetchMultipleContent('movie', $movieIds);
            $itemsDetails = array_merge($itemsDetails, $movieDetails);
        }

        if (!empty($tvIds)) {
            $tvDetails = $this->fetchMultipleContent('tv', $tvIds);
            $itemsDetails = array_merge($itemsDetails, $tvDetails);
        }

        // Enrichit les détails avec les infos de la liste
        $enrichedItems = [];
        foreach ($itemsDetails as $key => $detail) {
            if (isset($itemsMap[$key]) && $detail !== null) {
                $detail['listItemId'] = $itemsMap[$key]->getId();
                $detail['isSeries'] = ($itemsMap[$key]->getTmdbType() === 'tv');
                $detail['addedAt'] = $itemsMap[$key]->getAddedAt();
                $enrichedItems[] = $detail;
            }
        }

        // Trie par date d'ajout (plus récent en premier)
        usort($enrichedItems, function ($a, $b) {
            return $b['addedAt'] <=> $a['addedAt'];
        });

        return $this->render('movie_list/show.html.twig', [
            'movie_list' => $movieList,
            'items' => $enrichedItems,
        ]);
    }

    /**
     * Supprime un élément d'une liste (Page avec redirection)
     * SÉCURISÉ : Vérifie l'ownership et le token CSRF
     */
    #[Route('/item/{id}/supprimer', name: 'app_movie_list_delete_item', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteItem(
        Request $request,
        int $id,
        EntityManagerInterface $entityManager,
        MovieListItemRepository $movieListItemRepository
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Récupère l'item
        $movieListItem = $movieListItemRepository->find($id);

        if (!$movieListItem) {
            $this->addFlash('error', 'Élément introuvable.');
            return $this->redirectToRoute('app_movie_list_index');
        }

        $movieList = $movieListItem->getMovieList();

        // SÉCURITÉ : Vérifie l'ownership
        if ($movieList->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à supprimer cet élément.');
        }

        // SÉCURITÉ : Vérifie le token CSRF
        if (!$this->isCsrfTokenValid('delete' . $movieListItem->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_movie_list_show', ['id' => $movieList->getId()]);
        }

        try {
            $entityManager->remove($movieListItem);
            $entityManager->flush();

            $this->logger->info('Item supprimé de la liste', [
                'user_id' => $user->getId(),
                'list_id' => $movieList->getId(),
                'item_id' => $id
            ]);

            $this->addFlash('success', 'L\'élément a été retiré de la liste avec succès.');
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression', [
                'user_id' => $user->getId(),
                'item_id' => $id,
                'exception' => $e->getMessage()
            ]);

            $this->addFlash('error', 'Une erreur est survenue lors de la suppression.');
        }

        return $this->redirectToRoute('app_movie_list_show', ['id' => $movieList->getId()]);
    }

    /**
     * Supprime un élément d'une liste (AJAX)
     * SÉCURISÉ : Vérifie l'ownership et le token CSRF
     */
    #[Route('/item/{id}', name: 'app_movie_list_delete_item_ajax', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteItemAjax(
        Request $request,
        int $id,
        EntityManagerInterface $entityManager,
        MovieListItemRepository $movieListItemRepository
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Récupère l'item
        $movieListItem = $movieListItemRepository->find($id);

        if (!$movieListItem) {
            return new JsonResponse(
                ['message' => 'Élément introuvable.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $movieList = $movieListItem->getMovieList();

        // SÉCURITÉ : Vérifie l'ownership
        if ($movieList->getUser() !== $user) {
            return new JsonResponse(
                ['message' => 'Accès non autorisé.'],
                Response::HTTP_FORBIDDEN
            );
        }

        // SÉCURITÉ : Vérifie le token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $movieListItem->getId(), $token)) {
            return new JsonResponse(
                ['message' => 'Token de sécurité invalide.'],
                Response::HTTP_FORBIDDEN
            );
        }

        try {
            $listName = $movieList->getName();

            $entityManager->remove($movieListItem);
            $entityManager->flush();

            $this->logger->info('Item supprimé de la liste via AJAX', [
                'user_id' => $user->getId(),
                'list_id' => $movieList->getId(),
                'item_id' => $id
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => "Retiré de {$listName} avec succès"
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression AJAX', [
                'user_id' => $user->getId(),
                'item_id' => $id,
                'exception' => $e->getMessage()
            ]);

            return new JsonResponse(
                ['message' => 'Une erreur est survenue lors de la suppression.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Supprime une liste complète
     */
    #[Route('/{id}/supprimer', name: 'app_movie_list_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        int $id,
        EntityManagerInterface $entityManager
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // SÉCURITÉ : Charge uniquement les listes de l'utilisateur
        $movieList = $this->movieListRepository->findOneBy([
            'id' => $id,
            'user' => $user
        ]);

        if (!$movieList) {
            throw $this->createAccessDeniedException('Cette liste n\'existe pas ou vous n\'y avez pas accès.');
        }

        // Empêcher la suppression des listes système
        if ($movieList->isSystem()) {
            $this->addFlash('error', 'La liste "' . $movieList->getName() . '" ne peut pas être supprimée car c\'est une liste système.');
            return $this->redirectToRoute('app_movie_list_index');
        }

        // SÉCURITÉ : Vérifie le token CSRF
        if (!$this->isCsrfTokenValid('delete' . $movieList->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_movie_list_index');
        }

        try {
            $listName = $movieList->getName();
            $entityManager->remove($movieList);
            $entityManager->flush();

            $this->logger->info('Liste supprimée', [
                'user_id' => $user->getId(),
                'list_id' => $id,
                'list_name' => $listName
            ]);

            $this->addFlash('success', sprintf('La liste "%s" a été supprimée avec succès.', $listName));
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression de la liste', [
                'user_id' => $user->getId(),
                'list_id' => $id,
                'exception' => $e->getMessage()
            ]);

            $this->addFlash('error', 'Une erreur est survenue lors de la suppression de la liste.');
        }

        return $this->redirectToRoute('app_movie_list_index');
    }

    /**
     * Récupère ou crée la liste "Favoris" pour un utilisateur
     * Méthode helper réutilisable
     */
    private function getOrCreateFavoritesList(\App\Entity\User $user, EntityManagerInterface $entityManager): MovieList
    {
        foreach ($user->getMovieLists() as $list) {
            if ($list->getName() === 'Favoris') {
                return $list;
            }
        }

        // La liste n'existe pas, on la crée
        $favoritesList = new MovieList();
        $favoritesList->setName('Favoris');
        $favoritesList->setUser($user);
        $favoritesList->setCreatedAt(new \DateTimeImmutable());
        $favoritesList->setIsSystem(true); // Marquer comme liste système

        $entityManager->persist($favoritesList);
        $entityManager->flush();

        return $favoritesList;
    }

    /**
     * OPTIMISATION : Récupère plusieurs contenus en parallèle avec mise en cache
     *
     * @param string $type 'movie' ou 'tv'
     * @param array $ids Liste des IDs TMDb
     * @return array Tableau associatif [type_id => détails]
     */
    private function fetchMultipleContent(string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // Utilise un cache global pour tous les IDs de ce type
        $cacheKey = sprintf('batch_%s_%s', $type, md5(implode(',', array_values($ids))));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($type, $ids) {
            $item->expiresAfter(self::CACHE_TTL_CONTENT);

            $results = [];

            // Limite le nombre de requêtes parallèles pour éviter de surcharger l'API
            $chunks = array_chunk($ids, self::MAX_PARALLEL_REQUESTS);

            foreach ($chunks as $chunk) {
                $chunkResponses = [];

                // Lance toutes les requêtes en parallèle pour ce chunk
                foreach ($chunk as $id) {
                    try {
                        $chunkResponses[$id] = $this->client->request('GET', self::API_BASE_URL . "/{$type}/{$id}", [
                            'query' => [
                                'api_key' => $this->apiKey,
                                'language' => 'fr-FR',
                            ],
                            'timeout' => self::API_TIMEOUT,
                        ]);
                    } catch (\Exception $e) {
                        $this->logger->warning("Erreur lors de la requête pour {$type}/{$id}", [
                            'exception' => $e->getMessage()
                        ]);
                    }
                }

                // Récupère les réponses
                foreach ($chunkResponses as $id => $response) {
                    try {
                        if ($response->getStatusCode() === 200) {
                            $data = $response->toArray();
                            $results[$type . '_' . $id] = $data;
                        }
                    } catch (TransportExceptionInterface $e) {
                        $this->logger->error("Erreur réseau pour {$type}/{$id}", [
                            'exception' => $e->getMessage()
                        ]);
                    } catch (\Exception $e) {
                        $this->logger->error("Erreur décodage JSON pour {$type}/{$id}", [
                            'exception' => $e->getMessage()
                        ]);
                    }
                }

                // Petit délai entre les chunks pour respecter les rate limits
                if (count($chunks) > 1) {
                    usleep(100000); // 100ms
                }
            }

            return $results;
        });
    }

    protected function render(string $view, array $parameters = [], ?Response $response = null): Response
    {
        // Récupère les genres depuis le cache ou l'API
        $parameters['allGenres'] = $this->getAllGenres();
        return parent::render($view, $parameters, $response);
    }

    private function getAllGenres(): array
    {
        return $this->cache->get('all_genres_menu', function (ItemInterface $item) {
            $item->expiresAfter(86400); // 24h

            // Récupère les genres films
            $movieGenresData = $this->makeApiRequest('/genre/movie/list');
            $movieGenres = array_map(fn($g) => [
                'name' => $g['name'],
                'id' => $g['id'],
                'icon' => $this->getGenreIcon($g['id'])
            ], $movieGenresData['genres'] ?? []);

            // Ajoute les séries
            $tvGenres = [
                ['name' => 'Séries', 'id' => 'tv_top_rated', 'icon' => 'fa-tv']
            ];

            return [
                'movie_genres' => $movieGenres,
                'tv_genres' => $tvGenres
            ];
        });
    }

    private function makeApiRequest(string $endpoint): array
    {
        try {
            $response = $this->client->request('GET', self::API_BASE_URL . $endpoint, [
                'query' => [
                    'api_key' => $this->apiKey,
                    'language' => 'fr-FR',
                ],
                'timeout' => self::API_TIMEOUT,
            ]);

            if ($response->getStatusCode() === 200) {
                return $response->toArray();
            }

            $this->logger->error("Erreur API TMDb: {$endpoint}", [
                'status' => $response->getStatusCode()
            ]);

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Exception lors de la requête API: {$endpoint}", [
                'exception' => $e->getMessage()
            ]);

            return [];
        }
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