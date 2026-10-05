<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatusController extends AbstractController
{
    #[Route('/', name: 'app_status', methods: ['GET'])]
    public function status(): Response
    {
        return $this->redirectToRoute($this->isGranted('ROLE_USER') ? 'app_planet' : 'app_login');
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'service' => '2moons',
            'status' => 'ok',
        ]);
    }
}
