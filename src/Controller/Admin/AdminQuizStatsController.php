<?php

namespace App\Controller\Admin;

use App\Repository\QuizRepository;
use App\Repository\QuizResultRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/quiz/stats')]
#[IsGranted('ROLE_ADMIN')]
class AdminQuizStatsController extends AbstractController
{
    #[Route('/', name: 'admin_quiz_stats')]
    public function dashboard(
        QuizRepository $quizRepository,
        QuizResultRepository $resultRepository
    ): Response {
        $quizzes = $quizRepository->findAll();

        // Statistiques globales
        $totalQuizzes = count($quizzes);
        $totalResults = $resultRepository->count([]);

        // Calcul du score moyen global
        $allResults = $resultRepository->findAll();
        $totalScore = 0;
        foreach ($allResults as $result) {
            $totalScore += $result->getScore();
        }
        $averageScore = $totalResults > 0 ? round($totalScore / $totalResults, 2) : 0;

        // Quiz le plus populaire
        $popularQuiz = null;
        $maxPlays = 0;
        foreach ($quizzes as $quiz) {
            $plays = $quiz->getPlayCount();
            if ($plays > $maxPlays) {
                $maxPlays = $plays;
                $popularQuiz = $quiz;
            }
        }

        // Quiz le plus difficile (score moyen le plus bas)
        $hardestQuiz = null;
        $lowestAverage = 10;
        foreach ($quizzes as $quiz) {
            if ($quiz->getPlayCount() > 0) {
                $avg = $quiz->getAverageScore();
                if ($avg < $lowestAverage) {
                    $lowestAverage = $avg;
                    $hardestQuiz = $quiz;
                }
            }
        }

        // Statistiques par quiz
        $quizStats = [];
        foreach ($quizzes as $quiz) {
            $quizStats[] = [
                'quiz' => $quiz,
                'plays' => $quiz->getPlayCount(),
                'avgScore' => $quiz->getAverageScore(),
            ];
        }

        // Trier par popularité
        usort($quizStats, fn($a, $b) => $b['plays'] <=> $a['plays']);

        return $this->render('admin/quiz/stats.html.twig', [
            'totalQuizzes' => $totalQuizzes,
            'totalPlays' => $totalResults,
            'averageScore' => $averageScore,
            'popularQuiz' => $popularQuiz,
            'hardestQuiz' => $hardestQuiz,
            'quizStats' => $quizStats,
        ]);
    }

    #[Route('/{id}', name: 'admin_quiz_stats_detail', requirements: ['id' => '\d+'])]
    public function detail(int $id, QuizRepository $quizRepository): Response
    {
        $quiz = $quizRepository->find($id);

        if (!$quiz) {
            throw $this->createNotFoundException('Quiz introuvable');
        }

        $results = $quiz->getResults();

        // Distribution des scores
        $scoreDistribution = array_fill(0, 11, 0); // 0 à 10
        foreach ($results as $result) {
            $scoreDistribution[$result->getScore()]++;
        }

        // Taux de réussite par question (si on stockait les réponses détaillées)
        // TODO: Implémenter si besoin

        return $this->render('admin/quiz/stats_detail.html.twig', [
            'quiz' => $quiz,
            'totalPlays' => $results->count(),
            'averageScore' => $quiz->getAverageScore(),
            'scoreDistribution' => $scoreDistribution,
        ]);
    }
}