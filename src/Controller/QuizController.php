<?php

namespace App\Controller;

use App\Entity\Quiz;
use App\Entity\QuizResult;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route('/quiz')]
class QuizController extends AbstractController
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

    #[Route('/', name: 'quiz_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $quizzes = $em->getRepository(Quiz::class)->findBy(
            ['isActive' => true],
            ['createdAt' => 'DESC']
        );

        return $this->render('quiz/index.html.twig', [
            'quizzes' => $quizzes,
        ]);
    }

    #[Route('/{id}/play', name: 'quiz_play', requirements: ['id' => '\d+'])]
    public function play(Quiz $quiz): Response
    {
        if (!$quiz->isActive()) {
            $this->addFlash('error', 'Ce quiz n\'est pas disponible pour le moment.');
            return $this->redirectToRoute('quiz_index');
        }

        return $this->render('quiz/play.html.twig', [
            'quiz' => $quiz,
            'questions' => $quiz->getQuestions(),
        ]);
    }

    #[Route('/{id}/submit', name: 'quiz_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(
        Request $request,
        Quiz $quiz,
        EntityManagerInterface $em
    ): Response {
        $data = $request->request->all();
        $score = 0;
        $totalQuestions = $quiz->getQuestions()->count();

        foreach ($quiz->getQuestions() as $question) {
            $userAnswer = $data['question_' . $question->getId()] ?? '';
            if ($userAnswer === $question->getCorrectAnswer()) {
                $score++;
            }
        }

        $result = new QuizResult();
        $result->setQuiz($quiz)
            ->setScore($score)
            ->setReward($this->getRewardFromScore($score, $totalQuestions));

        /** @var User|null $user */
        $user = $this->getUser();
        if ($user) {
            $result->setUser($user);
        }

        $em->persist($result);
        $em->flush();

        return $this->render('quiz/result.html.twig', [
            'quiz' => $quiz,
            'score' => $score,
            'totalQuestions' => $totalQuestions,
            'reward' => $result->getReward(),
            'result' => $result,
        ]);
    }

    #[Route('/mes-resultats', name: 'quiz_my_results')]
    public function myResults(EntityManagerInterface $em): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour voir vos résultats.');
            return $this->redirectToRoute('app_login');
        }

        $results = $em->getRepository(QuizResult::class)->findBy(
            ['user' => $user],
            ['completedAt' => 'DESC']
        );

        $totalPlays = count($results);
        $totalScore = 0;
        $perfectScores = 0;

        foreach ($results as $result) {
            $totalScore += $result->getScore();
            if ($result->getScore() === $result->getQuiz()->getQuestions()->count()) {
                $perfectScores++;
            }
        }

        $averageScore = $totalPlays > 0 ? round($totalScore / $totalPlays, 2) : 0;

        return $this->render('quiz/my_results.html.twig', [
            'results' => $results,
            'totalPlays' => $totalPlays,
            'averageScore' => $averageScore,
            'perfectScores' => $perfectScores,
        ]);
    }

    private function getRewardFromScore(int $score, int $total): string
    {
        $percentage = ($score / $total) * 100;

        return match (true) {
            $percentage === 100 => '🏆 Maître du 7e Art',
            $percentage >= 80 => '🎬 Cinéphile expert',
            $percentage >= 50 => '🍿 Fan du dimanche',
            default => '🎞️ Spectateur distrait',
        };
    }
}
