<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\RegistrationService;
use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, RegistrationService $registration): Response
    {
        $form = $this->createForm(RegistrationType::class, new User());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $form->getData();
            $plainPassword = $form->get('plainPassword')->getData();
            try {
                $registration->register($user, $plainPassword);
            } catch (UniqueConstraintViolationException) {
                $form->addError(new FormError('Registration could not be completed. Check the details or try again.'));

                return $this->render('registration/register.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', 'Account created. You can now sign in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
