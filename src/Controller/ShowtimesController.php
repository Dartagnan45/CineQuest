<?php

namespace App\Controller;

use App\Service\ShowtimesProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Contrôleur API pour la recherche de séances de cinéma à proximité
 */
class ShowtimesController extends AbstractController
{
    public function __construct(
        private ShowtimesProvider $showtimesProvider,
        private LoggerInterface $logger
    ) {}

    /**
     * Recherche les cinémas et séances à proximité d'une position GPS
     *
     * @Route("/api/showtimes/nearby", name="api_showtimes_nearby", methods={"GET"})
     */
    #[Route('/api/showtimes/nearby', name: 'api_showtimes_nearby', methods: ['GET'])]
    public function getNearbyShowtimes(Request $request): JsonResponse
    {
        try {
            // Récupération des paramètres
            $latitude = $request->query->get('lat');
            $longitude = $request->query->get('lng');
            $movieId = $request->query->get('movieId');

            // Validation des paramètres
            if (!$latitude || !$longitude) {
                return $this->json([
                    'error' => 'Les paramètres lat et lng sont requis'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Conversion en float
            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            $movieId = $movieId ? (int) $movieId : null;

            // Validation des coordonnées
            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                return $this->json([
                    'error' => 'Coordonnées GPS invalides'
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->logger->info('Recherche de séances', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'movieId' => $movieId
            ]);

            // Appel du service pour récupérer les séances
            $cinemas = $this->showtimesProvider->findNearbyShowtimes(
                $latitude,
                $longitude,
                $movieId
            );

            // Retour JSON
            return $this->json([
                'success' => true,
                'cinemas' => $cinemas,
                'count' => count($cinemas),
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la recherche de séances', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'error' => 'Une erreur est survenue lors de la recherche des séances',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
