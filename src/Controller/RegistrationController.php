<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\MovieList;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RegistrationController extends AbstractController
{
    private const API_BASE_URL = 'https://api.themoviedb.org/3';
    private const CACHE_TTL_GENRES = 86400;

    public function __construct(
        private EmailVerifier $emailVerifier,
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

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var string $plainPassword */
                $plainPassword = $form->get('plainPassword')->getData();

                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

                $entityManager->persist($user);
                $entityManager->flush();

                // ========================================
                // CRÉATION DES DEUX LISTES SYSTÈME
                // ========================================

                // Liste 1: "Mon Panthéon" (favoris)
                $pantheonList = new MovieList();
                $pantheonList->setName('Mon Panthéon');
                $pantheonList->setUser($user);
                $pantheonList->setCreatedAt(new \DateTimeImmutable());
                $pantheonList->setIsSystem(true);
                $entityManager->persist($pantheonList);

                // Liste 2: "La Carte aux Trésors" (à voir)
                $treasureList = new MovieList();
                $treasureList->setName('La Carte aux Trésors');
                $treasureList->setUser($user);
                $treasureList->setCreatedAt(new \DateTimeImmutable());
                $treasureList->setIsSystem(true);
                $entityManager->persist($treasureList);

                $entityManager->flush();

                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address('dartagnan45@gmail.com', 'Julien'))
                        ->to((string) $user->getEmail())
                        ->subject('Please Confirm your Email')
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );

                $this->addFlash('success', '🎉 Votre compte a été créé avec succès ! Vos listes "Mon Panthéon" et "La Carte aux Trésors" sont prêtes. Veuillez vérifier votre email puis vous connecter.');

                return $this->redirectToRoute('app_login');
            } catch (UniqueConstraintViolationException $e) {
                $this->addFlash('error', '❌ Cet email est déjà utilisé. Si c\'est votre compte, veuillez vous <a href="' . $this->generateUrl('app_login') . '" class="alert-link">connecter ici</a>.');
            } catch (\Exception $e) {
                $this->addFlash('error', '❌ Une erreur est survenue lors de la création de votre compte. Veuillez réessayer.');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator, UserRepository $userRepository): Response
    {
        $id = $request->query->get('id');

        if (null === $id) {
            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('app_register');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        $this->addFlash('success', '✅ Votre email a été vérifié avec succès ! Vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }
}