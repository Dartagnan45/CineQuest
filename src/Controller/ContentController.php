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
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class ContentController extends AbstractController
{
    // --- Constantes pour les API et la configuration ---
    private const TMDB_BASE = 'https://api.themoviedb.org/3';
    private const OMDB_BASE = 'https://www.omdbapi.com/';
    private const REGION = 'FR'; // Région pour les watch providers
    private const LANGUAGE = 'fr-FR'; // Langue pour les résultats API

    // --- Configuration des Timeouts et Cache ---
    private const API_TIMEOUT_DETAIL = 10; // Timeout pour l'appel principal
    private const API_TIMEOUT_CANDIDATE = 6; // Timeout plus court pour les détails des recommandations
    private const CACHE_TTL_DETAIL = 6 * 3600; // Cache de 6h pour les détails principaux
    private const CACHE_TTL_OMDB = 24 * 3600; // Cache de 24h pour les données OMDb
    private const CACHE_TTL_PERSON = 24 * 3600; // Cache de 24h pour les détails personne
    private const CACHE_TTL_RECOMMENDATIONS = 12 * 3600; // Cache de 12h pour les recommandations calculées
    private const CACHE_TTL_CANDIDATE_DETAILS = 24 * 3600; // Cache de 24h pour les détails bruts des candidats
    private const CACHE_TTL_CANDIDATE_FAILURE = 1 * 3600; // Cache de 1h pour un échec API (éviter de retenter)

    // --- Configuration de l'algorithme de recommandation ---
    private const RECOMMENDATION_LIMIT = 8; // Nombre max de recommandations à afficher
    private const MIN_VOTE_COUNT_RECOMMENDATION = 75; // Nombre minimum de votes pour qu'un item soit recommandé
    // Pondération des scores pour l'algorithme
    private const SCORE_WEIGHTS = [
        'genre' => 25,      // Points par genre commun
        'keyword' => 12,    // Points par mot-clé commun
        'director' => 40,   // Points si même réalisateur/créateur
        'actor' => 10,      // Points par acteur commun (top 5)
        'high_rating' => 15, // Bonus pour note > 7.8
        'good_rating' => 8, // Bonus pour note > 6.8
    ];


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

    // --- Routes pour afficher les détails ---
    #[Route('/movie/{id}', name: 'movie_content', requirements: ['id' => '\d+'])]
    public function movieDetail(int $id): Response
    {
        return $this->renderContentDetail($id, false); // isSeries = false
    }

    #[Route('/tv/{id}', name: 'tv_content', requirements: ['id' => '\d+'])]
    public function tvDetail(int $id): Response
    {
        return $this->renderContentDetail($id, true); // isSeries = true
    }

    // --- Méthode principale pour le rendu des détails ---
    private function renderContentDetail(int $id, bool $isSeries): Response
    {
        $type = $isSeries ? 'tv' : 'movie'; // Détermine le type 'movie' ou 'tv'

        try {
            $this->logger->info('🎬 Début renderContentDetail', ['id' => $id, 'type' => $type]);

            /* 1) Récupération des données principales de TMDb (avec cache) */
            $baseItem = $this->cache->get("detail_{$type}_{$id}_v2", function (ItemInterface $ci) use ($type, $id) {
                $ci->expiresAfter(self::CACHE_TTL_DETAIL);
                $this->logger->info("🔧 Cache miss detail_{$type}_{$id}_v2. Appel API.", ['id' => $id, 'type' => $type]);
                return $this->fetchTmdbDetails($id, $type);
            });
            if (empty($baseItem)) { // Si le fetch a échoué (et retourné null)
                throw new \RuntimeException("Données TMDb introuvables pour {$type} ID {$id}");
            }
            $this->logger->info('✅ Item principal récupéré', ['title' => $baseItem['title'] ?? $baseItem['name'] ?? 'N/A']);

            /* 2) Extraction des plateformes de visionnage (Watch Providers) */
            $watchProviders = $this->extractWatchProviders($baseItem);
            $this->logger->info('📺 Watch Providers', ['counts' => array_map('count', $watchProviders)]);

            /* 3) Récupération des données OMDb (notes externes) */
            $omdb = $this->fetchOmdbData($baseItem);
            $this->logger->info('ℹ️ Données OMDb', ['found' => $omdb !== null]);

            /* 4) Génération des liens externes */
            $links = $this->generateLinks($baseItem, $type, $id);

            /* 5) Calcul des Badges */
            $badges = $this->movieBadgeService->decideBadges($baseItem, $omdb ?? null, $isSeries);

            /* 6) Détection "Au cinéma" (pour les films) */
            $isNowPlaying = $this->isCurrentlyInTheaters($baseItem, $isSeries);

            /* 7) NOUVEL ALGORITHME DE RECOMMANDATIONS PERSONNALISÉES */
            $recommendationCacheKey = "recommendations_{$type}_{$id}_v3"; // v3 pour invalider ancien cache
            $customRecommendations = $this->cache->get($recommendationCacheKey, function (ItemInterface $ci) use ($baseItem, $isSeries, $type, $recommendationCacheKey) {
                $ci->expiresAfter(self::CACHE_TTL_RECOMMENDATIONS);
                $this->logger->info("🔧 Cache miss {$recommendationCacheKey}. Calcul recommandations.");
                return $this->generateCustomRecommendations($baseItem, $isSeries, $type, self::RECOMMENDATION_LIMIT);
            });
            $this->logger->info('💡 Recommandations', ['count' => count($customRecommendations)]);


            $this->logger->info('🎨 Préparation rendu template', ['template' => 'what_to_watch/content.html.twig']);

            /* 8) Rendu du template Twig */
            return $this->render('what_to_watch/content.html.twig', [
                'item'                  => $baseItem,
                'isSeries'              => $isSeries,
                'omdb'                  => $omdb,
                'watchProviders'        => $watchProviders,
                'links'                 => $links,
                'badges'                => $badges,
                'isNowPlaying'          => $isNowPlaying,
                'customRecommendations' => $customRecommendations,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('❌ ERREUR COMPLÈTE renderContentDetail', [
                'id' => $id,
                'type' => $type,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                //'trace' => $e->getTraceAsString() // Décommenter pour debug complet
            ]);
            // Affiche une page 404 standard
            throw $this->createNotFoundException($isSeries ? 'Série introuvable.' : 'Film introuvable.');
        }
    }

    /**
     * Fonction dédiée pour l'appel API TMDb principal.
     */
    private function fetchTmdbDetails(int $id, string $type): ?array
    {
        $url = sprintf('%s/%s/%d', self::TMDB_BASE, $type, $id);
        $appendToResponse = 'videos,credits,external_ids,watch_providers,keywords,recommendations,similar';
        $this->logger->info('📡 Appel API (fetchTmdbDetails)', ['url' => $url, 'append' => $appendToResponse]);
        try {
            $response = $this->client->request('GET', $url, [
                'query' => ['api_key' => $this->tmdbApiKey, 'language' => self::LANGUAGE, 'append_to_response' => $appendToResponse],
                'timeout' => self::API_TIMEOUT_DETAIL
            ]);
            $statusCode = $response->getStatusCode();
            $this->logger->info('✅ Réponse API (fetchTmdbDetails)', ['status' => $statusCode]);
            if ($statusCode !== 200) {
                throw new \RuntimeException("TMDb HTTP {$statusCode}");
            }
            $data = $response->toArray();
            // Assurer existence des clés importantes pour éviter les erreurs
            $data['genres'] = $data['genres'] ?? [];
            $data['credits'] = $data['credits'] ?? ['cast' => [], 'crew' => []];
            $data['keywords'] = $data['keywords']['keywords'] ?? $data['keywords']['results'] ?? [];
            $data['recommendations'] = $data['recommendations'] ?? ['results' => []];
            $data['similar'] = $data['similar'] ?? ['results' => []];
            $data['watch_providers'] = $data['watch_providers'] ?? ['results' => []];
            $data['videos'] = $data['videos'] ?? ['results' => []];
            $data['external_ids'] = $data['external_ids'] ?? [];
            $data['created_by'] = $data['created_by'] ?? []; // Pour les séries
            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('💥 Erreur fetchTmdbDetails', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            return null; // Retourne null en cas d'erreur
        }
    }

    /**
     * NOUVEL ALGORITHME : Génère des recommandations personnalisées plus pertinentes.
     */
    private function generateCustomRecommendations(array $baseItem, bool $isSeries, string $type, int $limit): array
    {
        $this->logger->info('🚀 Calcul recommandations', ['base_id' => $baseItem['id']]);
        // 1. Caractéristiques base
        $baseGenres = array_column($baseItem['genres'] ?? [], 'id');
        $baseKeywords = array_column($baseItem['keywords'] ?? [], 'id');
        $baseDirectorId = null;
        if (!$isSeries) {
            foreach ($baseItem['credits']['crew'] ?? [] as $crew) {
                if (($crew['job'] ?? '') === 'Director') {
                    $baseDirectorId = $crew['id'];
                    break;
                }
            }
        } else {
            $baseDirectorId = $baseItem['created_by'][0]['id'] ?? null;
        }
        $baseActorIds = array_column(array_slice($baseItem['credits']['cast'] ?? [], 0, 5), 'id');
        $this->logger->debug('📝 Caractéristiques base', ['G' => $baseGenres, 'K' => $baseKeywords, 'D' => $baseDirectorId, 'A' => $baseActorIds]);

        // 2. Candidats
        $candidatesRaw = array_merge($baseItem['recommendations']['results'] ?? [], $baseItem['similar']['results'] ?? []);
        $uniqueCandidates = [];
        foreach ($candidatesRaw as $candidate) {
            $cId = $candidate['id'] ?? null;
            if ($cId && $cId !== $baseItem['id'] && !empty($candidate['poster_path']) && !isset($uniqueCandidates[$cId])) {
                // media_type est crucial, on essaie de le deviner s'il manque
                $cType = $candidate['media_type'] ?? ($candidate['first_air_date'] ?? null ? 'tv' : ($candidate['release_date'] ?? null ? 'movie' : null));
                if ($cType) {
                    $candidate['media_type'] = $cType;
                    $uniqueCandidates[$cId] = $candidate;
                }
            }
        }
        $this->logger->info('🔍 Candidats uniques', ['count' => count($uniqueCandidates)]);
        if (empty($uniqueCandidates)) {
            return [];
        }

        // 3. Détails candidats
        $candidateDetails = $this->fetchMultipleCandidateDetails(array_values($uniqueCandidates));

        // 4. Scoring
        $scoredCandidates = [];
        foreach ($uniqueCandidates as $cId => $candidate) {
            $details = $candidateDetails[$cId] ?? null;
            // Ignorer si échec fetch ou pas assez de votes
            if (!$details || ($details['vote_count'] ?? 0) < self::MIN_VOTE_COUNT_RECOMMENDATION) {
                continue;
            }
            $score = 0;
            $reasons = [];
            $cType = $details['fetched_type'];
            $cIsSeries = ($cType === 'tv');

            // Score Genres
            $cGenres = array_column($details['genres'] ?? [], 'id');
            $commonG = count(array_intersect($baseGenres, $cGenres));
            if ($commonG > 0) {
                $score += $commonG * self::SCORE_WEIGHTS['genre'];
                $reasons[] = "{$commonG}G";
            }
            // Score Keywords
            $cKeywords = array_column(array_slice($details['keywords'] ?? [], 0, 15), 'id');
            $commonK = count(array_intersect($baseKeywords, $cKeywords));
            if ($commonK > 0) {
                $score += $commonK * self::SCORE_WEIGHTS['keyword'];
                $reasons[] = "{$commonK}K";
            }
            // Score Réal/Créateur
            $cDirectorId = null;
            if (!$cIsSeries) {
                foreach ($details['credits']['crew'] ?? [] as $crew) {
                    if (($crew['job'] ?? '') === 'Director') {
                        $cDirectorId = $crew['id'];
                        break;
                    }
                }
            } else {
                $cDirectorId = $details['created_by'][0]['id'] ?? null;
            }
            if ($baseDirectorId && $cDirectorId && $baseDirectorId === $cDirectorId) {
                $score += self::SCORE_WEIGHTS['director'];
                $reasons[] = "Real";
            }
            // Score Acteurs
            $cActorIds = array_column(array_slice($details['credits']['cast'] ?? [], 0, 5), 'id');
            $commonA = count(array_intersect($baseActorIds, $cActorIds));
            if ($commonA > 0) {
                $score += $commonA * self::SCORE_WEIGHTS['actor'];
                $reasons[] = "{$commonA}A";
            }
            // Note
            $voteAvg = $details['vote_average'] ?? 0;
            if ($voteAvg > 7.8) {
                $score += self::SCORE_WEIGHTS['high_rating'];
                $reasons[] = "N++";
            } elseif ($voteAvg > 6.8) {
                $score += self::SCORE_WEIGHTS['good_rating'];
                $reasons[] = "N+";
            }

            if ($score > 0) {
                $scoredCandidates[] = [
                    'id' => $cId,
                    'title' => $details['title'] ?? $details['name'] ?? '?',
                    'name' => $details['name'] ?? $details['title'] ?? '?',
                    'poster_path' => $details['poster_path'] ?? null,
                    'vote_average' => $voteAvg,
                    'isSeries' => $cIsSeries,
                    'score' => $score,
                    'score_reasons' => implode('+', $reasons), // Raisons courtes pour debug
                ];
            }
        }

        // 5. Tri
        usort($scoredCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $this->logger->info('✅ Recommandations triées', ['count' => count($scoredCandidates)]);
        return array_slice($scoredCandidates, 0, $limit);
    }

    /**
     * HELPER : Récupère les détails pour plusieurs candidats en parallèle avec cache.
     */
    private function fetchMultipleCandidateDetails(array $candidates): array
    {
        $results = [];
        if (empty($candidates)) {
            return $results;
        }
        $this->logger->info('⏳ Début fetchMultipleCandidateDetails', ['count' => count($candidates)]);
        $requestsByType = ['movie' => [], 'tv' => []];
        foreach ($candidates as $c) {
            if ($c['media_type'] && $c['id']) {
                $requestsByType[$c['media_type']][$c['id']] = $c['id'];
            }
        }

        $appendToResponse = 'credits,genres,keywords';
        foreach ($requestsByType as $type => $ids) {
            if (empty($ids)) continue;
            $this->logger->debug("🔧 Fetching details batch {$type}", ['ids' => array_keys($ids)]);
            // Appel à la fonction corrigée
            $batchData = $this->fetchBatchDetailsWithCache($type, array_keys($ids), $appendToResponse);
            foreach ($batchData as $id => $data) {
                if ($data) { // S'assurer que les données ne sont pas null (échec fetch/cache)
                    $data['fetched_type'] = $type;
                    $data['keywords'] = $data['keywords']['keywords'] ?? $data['keywords']['results'] ?? [];
                    $data['credits'] = $data['credits'] ?? ['cast' => [], 'crew' => []];
                    $data['created_by'] = $data['created_by'] ?? [];
                    $results[$id] = $data;
                } else {
                    $results[$id] = null; // Marquer comme échec
                }
            }
        }
        $this->logger->info('🏁 Fin fetchMultipleCandidateDetails', ['fetched' => count(array_filter($results))]);
        return $results;
    }

    /**
     * HELPER : Fait les appels API en batch (séquentiel) pour un type donné avec cache.
     * *** VERSION CORRIGÉE UTILISANT $this->cache->get() CORRECTEMENT ***
     */
    private function fetchBatchDetailsWithCache(string $type, array $ids, string $appendToResponse): array
    {
        $batchResults = [];
        $this->logger->info("⚙️ Début fetchBatchDetailsWithCache pour {$type}", ['count' => count($ids)]);

        foreach ($ids as $id) {
            $cacheKey = "candidate_detail_{$type}_{$id}";
            // $this->cache->get() fait tout :
            // 1. Vérifie si $cacheKey existe.
            // 2. Si oui, retourne la valeur.
            // 3. Si non, exécute le callback, sauvegarde le résultat, et le retourne.
            $data = $this->cache->get($cacheKey, function (ItemInterface $item) use ($type, $id, $appendToResponse, $cacheKey) {
                $this->logger->debug("❌ MISS cache pour {$cacheKey}");
                try {
                    $url = sprintf('%s/%s/%d', self::TMDB_BASE, $type, $id);
                    $response = $this->client->request('GET', $url, [
                        'query' => [
                            'api_key' => $this->tmdbApiKey,
                            'language' => self::LANGUAGE,
                            'append_to_response' => $appendToResponse
                        ],
                        'timeout' => self::API_TIMEOUT_CANDIDATE
                    ]);

                    if ($response->getStatusCode() === 200) {
                        $this->logger->debug("👍 Succès API & cache MAJ {$cacheKey}");
                        $item->expiresAfter(self::CACHE_TTL_CANDIDATE_DETAILS); // TTL long pour succès
                        return $response->toArray(); // Sauvegardé et retourné
                    } else {
                        // *** CORRECTION FAUTE DE FRAPPE : $this->logger ***
                        $this->logger->warning("⚠️ API non-200 {$type} ID {$id}", ['status' => $response->getStatusCode()]);
                        $item->expiresAfter(self::CACHE_TTL_CANDIDATE_FAILURE); // TTL court pour échec
                        return null; // Sauvegarde null
                    }
                } catch (\Throwable $e) {
                    $this->logger->error("💥 Erreur traitement API (batch-seq)", ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
                    $item->expiresAfter(self::CACHE_TTL_CANDIDATE_FAILURE); // TTL court pour échec
                    return null; // Sauvegarde null
                }
            });

            // $data sera soit la donnée cachée, soit la donnée fraîchement fetchée, soit null (en cas d'échec)
            if ($data !== null) {
                $batchResults[$id] = $data;
            } else {
                $batchResults[$id] = null; // Assurer que les échecs cachés sont bien null
            }
        }
        return $batchResults;
    }

    // --- Fonctions utilitaires existantes (inchangées) ---

    /** Vérifie si un film est sorti récemment (moins de 60 jours) */
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
            $this->logger->warning('Erreur parsing date cinéma', ['date' => $item['release_date'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** Récupère les données supplémentaires depuis OMDb */
    private function fetchOmdbData(array $item): ?array
    {
        try {
            if (!$this->omdbApiKey) {
                return null;
            }
            $imdbId = $item['external_ids']['imdb_id'] ?? $item['imdb_id'] ?? null;
            if (!$imdbId) {
                return null;
            }
            return $this->cache->get("omdb_{$imdbId}", function (ItemInterface $ci) use ($imdbId) {
                $ci->expiresAfter(self::CACHE_TTL_OMDB);
                $resp = $this->client->request('GET', self::OMDB_BASE, ['query' => ['i' => $imdbId, 'apikey' => $this->omdbApiKey, 'r' => 'json'], 'timeout' => 8]);
                if ($resp->getStatusCode() !== 200) {
                    return null;
                }
                $data = $resp->toArray(false);
                // Formatage notes
                $data['imdbRatingFloat'] = (!empty($data['imdbRating']) && $data['imdbRating'] !== 'N/A') ? (float)$data['imdbRating'] : null;
                $data['rottenTomatoesPercent'] = null;
                $data['metacriticScoreInt'] = null;
                if (!empty($data['Ratings']) && is_array($data['Ratings'])) {
                    foreach ($data['Ratings'] as $r) {
                        if (($r['Source'] ?? '') === 'Rotten Tomatoes' && !empty($r['Value'])) {
                            $data['rottenTomatoesPercent'] = (float)str_replace('%', '', $r['Value']);
                        }
                        if (($r['Source'] ?? '') === 'Metacritic' && !empty($r['Value']) && strpos($r['Value'], '/') !== false) {
                            $parts = explode('/', $r['Value'], 2);
                            $data['metacriticScoreInt'] = (int)$parts[0];
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
            $this->logger->warning('Erreur OMDb', ['message' => $e->getMessage(), 'item_id' => $item['id'] ?? null]);
            return null;
        }
    }

    /** Extrait les informations sur les plateformes de visionnage pour la région FR */
    private function extractWatchProviders(array $item): array
    {
        $providers = ['flatrate' => [], 'rent' => [], 'buy' => []];
        $this->logger->debug('🔍 Structure watch_providers', ['has_wp' => isset($item['watch_providers']), 'has_res' => isset($item['watch_providers']['results']), 'has_FR' => isset($item['watch_providers']['results'][self::REGION])]);
        $root = $item['watch_providers']['results'][self::REGION] ?? null;
        if (!$root) {
            $this->logger->info("🤷 Pas de watch_provider FR", ['id' => $item['id'] ?? 'N/A']);
            return $providers;
        }
        if (!empty($root['flatrate'])) {
            $providers['flatrate'] = $root['flatrate'];
        }
        if (!empty($root['rent'])) {
            $providers['rent'] = $root['rent'];
        }
        if (!empty($root['buy'])) {
            $providers['buy'] = $root['buy'];
        }
        $this->logger->info("👍 Watch_provider FR extraits", ['id' => $item['id'] ?? 'N/A', 'counts' => array_map('count', $providers)]);
        return $providers;
    }

    /** Génère les liens vers les plateformes externes */
    private function generateLinks(array $item, string $type, int $id): array
    {
        $links = ['tmdb' => null, 'imdb' => null, 'rotten_tomatoes' => null, 'metacritic' => null];
        $links['tmdb'] = sprintf('https://www.themoviedb.org/%s/%d', $type, $id);
        $imdbId = $item['external_ids']['imdb_id'] ?? $item['imdb_id'] ?? null;
        if ($imdbId) {
            $links['imdb'] = 'https://www.imdb.com/title/' . $imdbId . '/';
        }
        $title = $item['title'] ?? $item['name'] ?? '';
        if ($title) {
            $rtSlug = $this->slugWithUnderscores($title);
            $rtPrefix = $type === 'movie' ? 'm' : 'tv';
            $links['rotten_tomatoes'] = sprintf('https://www.rottentomatoes.com/%s/%s', $rtPrefix, $rtSlug);
            $mcSlug = $this->slugWithDashes($title);
            $links['metacritic'] = sprintf('https://www.metacritic.com/%s/%s', $type, $mcSlug);
        }
        return $links;
    }

    /** Helper pour slug avec underscores */
    private function slugWithUnderscores(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        $slug = preg_replace('/_+/', '_', $slug);
        return $slug ?: 'titre';
    }
    /** Helper pour slug avec tirets */
    private function slugWithDashes(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug ?: 'titre';
    }

    /** Affiche les détails d'une personne */
    #[Route('/person/{id}', name: 'person_details', requirements: ['id' => '\d+'])]
    public function personDetails(int $id): Response
    {
        try {
            $person = $this->cache->get("person_{$id}", function (ItemInterface $ci) use ($id) {
                $ci->expiresAfter(self::CACHE_TTL_PERSON);
                $resp = $this->client->request('GET', sprintf('%s/person/%d', self::TMDB_BASE, $id), [
                    'query' => ['api_key' => $this->tmdbApiKey, 'language' => self::LANGUAGE, 'append_to_response' => 'movie_credits,tv_credits,images'],
                    'timeout' => 10
                ]);
                return $resp->toArray();
            });
            $movieCredits = $person['movie_credits']['cast'] ?? [];
            $movieCrew = $person['movie_credits']['crew'] ?? [];
            $tvCredits = $person['tv_credits']['cast'] ?? [];
            $directedMovies = array_filter($movieCrew, fn($c) => ($c['job'] ?? null) === 'Director');
            $sortDescDate = fn($a, $b) => ($b['release_date'] ?? $b['first_air_date'] ?? '1900') <=> ($a['release_date'] ?? $a['first_air_date'] ?? '1900');
            usort($movieCredits, $sortDescDate);
            usort($directedMovies, $sortDescDate);
            usort($tvCredits, $sortDescDate);
            return $this->render('what_to_watch/person.html.twig', [
                'person' => $person,
                'movieCredits' => $movieCredits,
                'directedMovies' => $directedMovies,
                'tvCredits' => $tvCredits,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur détail personne', ['id' => $id, 'error' => $e->getMessage()]);
            throw $this->createNotFoundException('Personne introuvable.');
        }
    }
}
