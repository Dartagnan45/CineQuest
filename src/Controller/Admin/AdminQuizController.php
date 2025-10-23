<?php

namespace App\Controller\Admin;

use App\Entity\Quiz;
use App\Entity\Question;
use App\Form\QuizType;
use App\Repository\QuizRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/quiz')]
#[IsGranted('ROLE_ADMIN')]
class AdminQuizController extends AbstractController
{
    #[Route('/', name: 'admin_quiz_index')]
    public function index(QuizRepository $quizRepository): Response
    {
        return $this->render('admin/quiz/index.html.twig', [
            'quizzes' => $quizRepository->findAll(),
        ]);
    }

    #[Route('/nouveau', name: 'admin_quiz_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $quiz = new Quiz();
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Associer les questions au quiz
            foreach ($quiz->getQuestions() as $question) {
                $question->setQuiz($quiz);
            }

            $em->persist($quiz);
            $em->flush();

            $this->addFlash('success', '🎉 Quiz créé avec succès !');
            return $this->redirectToRoute('admin_quiz_index');
        }

        return $this->render('admin/quiz/form.html.twig', [
            'form' => $form,
            'quiz' => $quiz,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_quiz_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Quiz $quiz, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Mettre à jour les relations
            foreach ($quiz->getQuestions() as $question) {
                if (!$question->getQuiz()) {
                    $question->setQuiz($quiz);
                }
            }

            $em->flush();

            $this->addFlash('success', '✅ Quiz modifié avec succès !');
            return $this->redirectToRoute('admin_quiz_index');
        }

        return $this->render('admin/quiz/form.html.twig', [
            'form' => $form,
            'quiz' => $quiz,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_quiz_delete', methods: ['POST'])]
    public function delete(Request $request, Quiz $quiz, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $quiz->getId(), $request->request->get('_token'))) {
            $em->remove($quiz);
            $em->flush();

            $this->addFlash('success', '🗑️ Quiz supprimé avec succès !');
        }

        return $this->redirectToRoute('admin_quiz_index');
    }

    #[Route('/{id}/dupliquer', name: 'admin_quiz_duplicate', methods: ['POST'])]
    public function duplicate(Quiz $originalQuiz, EntityManagerInterface $em): Response
    {
        $newQuiz = new Quiz();
        $newQuiz->setTitle($originalQuiz->getTitle() . ' (Copie)')
            ->setTheme($originalQuiz->getTheme())
            ->setDifficulty($originalQuiz->getDifficulty());

        foreach ($originalQuiz->getQuestions() as $originalQuestion) {
            $newQuestion = new Question();
            $newQuestion->setText($originalQuestion->getText())
                ->setChoices($originalQuestion->getChoices())
                ->setCorrectAnswer($originalQuestion->getCorrectAnswer())
                ->setExplanation($originalQuestion->getExplanation())
                ->setQuiz($newQuiz);

            $newQuiz->addQuestion($newQuestion);
        }

        $em->persist($newQuiz);
        $em->flush();

        $this->addFlash('success', '📋 Quiz dupliqué avec succès !');
        return $this->redirectToRoute('admin_quiz_edit', ['id' => $newQuiz->getId()]);
    }

    #[Route('/{id}/apercu', name: 'admin_quiz_preview')]
    public function preview(Quiz $quiz): Response
    {
        return $this->render('quiz/play.html.twig', [
            'quiz' => $quiz,
            'questions' => $quiz->getQuestions(),
            'isPreview' => true,
        ]);
    }
}
