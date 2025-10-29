<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShowtimesProvider
{
    private const MAX_DISTANCE_KM = 25;
    private const CACHE_TTL = 1800; // 30 min
    private const API_VERSION = 'v201';

    public function __construct(
        private HttpClientInterface $client,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        #[Autowire('%env(MOVIEGLU_API_URL)%')] private string $apiUrl,
        #[Autowire('%env(MOVIEGLU_CLIENT)%')] private string $clientId,
        #[Autowire('%env(MOVIEGLU_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(MOVIEGLU_TERRITORY)%')] private string $territory,
        #[Autowire('%env(MOVIEGLU_AUTH)%')] private string $auth,
    ) {}

    private function getHeaders(float $lat, float $lng): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d\TH:i:s.000\Z');
        $geo = $this->territory === 'XX' ? '-22.0;14.0' : sprintf('%.4f;%.4f', $lat, $lng);
        return [
            'client' => $this->clientId,
            'x-api-key' => $this->apiKey,
            'authorization' => $this->auth,
            'territory' => $this->territory,
            'api-version' => self::API_VERSION,
            'device-datetime' => $now,
            'geolocation' => $geo,
            'accept' => 'application/json',
        ];
    }

    public function findNearbyShowtimes(float $latitude, float $longitude, ?int $movieId = null): array
    {
        $cacheKey = sprintf('showtimes_%s_%s_%s', $latitude, $longitude, $movieId ?? 'all');
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($latitude, $longitude, $movieId) {
            $item->expiresAfter(self::CACHE_TTL);
            try {
                $cinemas = $this->findNearbyCinemas($latitude, $longitude);
                if (empty($cinemas)) return [];
                $cinemasWithShowtimes = [];
                foreach ($cinemas as $cinema) {
                    $showtimes = $this->getCinemaShowtimes((string)$cinema['cinema_id'], $latitude, $longitude, $movieId);
                    if (!empty($showtimes)) {
                        $cinema['showtimes'] = $showtimes;
                        $cinemasWithShowtimes[] = $cinema;
                    }
                }
                usort($cinemasWithShowtimes, fn($a, $b) => $a['distance'] <=> $b['distance']);
                return $cinemasWithShowtimes;
            } catch (\Throwable $e) {
                $this->logger->error('Erreur MovieGlu API', ['error' => $e->getMessage(), 'latitude' => $latitude, 'longitude' => $longitude]);
                return [];
            }
        });
    }

    private function findNearbyCinemas(float $latitude, float $longitude): array
    {
        try {
            $response = $this->client->request('GET', $this->apiUrl . '/cinemasNearby/', [
                'query' => ['n' => 10],
                'headers' => $this->getHeaders($latitude, $longitude),
            ]);
            $data = $response->toArray(false);
            if (!isset($data['cinemas'])) return [];
            $cinemas = [];
            foreach ($data['cinemas'] as $cinema) {
                $distance = $this->calculateDistance($latitude, $longitude, $cinema['lat'] ?? 0, $cinema['lng'] ?? 0);
                if ($distance <= self::MAX_DISTANCE_KM) {
                    $cinemas[] = [
                        'cinema_id' => $cinema['cinema_id'] ?? null,
                        'name' => $cinema['cinema_name'] ?? 'Cinéma inconnu',
                        'address' => $cinema['address'] ?? '',
                        'city' => $cinema['city'] ?? '',
                        'distance' => round($distance, 1),
                        'lat' => $cinema['lat'] ?? null,
                        'lng' => $cinema['lng'] ?? null,
                    ];
                }
            }
            return $cinemas;
        } catch (\Throwable $e) {
            $this->logger->error('Erreur findNearbyCinemas', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function getCinemaShowtimes(string $cinemaId, float $lat, float $lng, ?int $movieId = null): array
    {
        try {
            $date = (new \DateTime('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
            $response = $this->client->request('GET', $this->apiUrl . '/cinemaShowTimes/', [
                'query' => ['cinema_id' => $cinemaId, 'date' => $date],
                'headers' => $this->getHeaders($lat, $lng),
            ]);
            $data = $response->toArray(false);
            if (!isset($data['films'])) return [];
            $showtimes = [];
            foreach ($data['films'] as $film) {
                if ($movieId && isset($film['film_id']) && (int)$film['film_id'] !== $movieId) continue;
                foreach ($film['showings']['Standard']['times'] ?? [] as $time) {
                    $showtimes[] = [
                        'film_id' => $film['film_id'] ?? null,
                        'title' => $film['film_name'] ?? '',
                        'time' => $time['start_time'] ?? 'N/A',
                        'screen' => $time['screen_name'] ?? null,
                    ];
                }
            }
            return $showtimes;
        } catch (\Throwable $e) {
            $this->logger->error('Erreur getCinemaShowtimes', ['cinema_id' => $cinemaId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}