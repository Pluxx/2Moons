<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\EconomyApplicationService;
use App\Application\IdempotencyConflict;
use App\Domain\Economy\RejectionCode;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlanetController extends AbstractController
{
    #[Route('/planet', name: 'app_planet', methods: ['GET'])]
    public function overview(EconomyApplicationService $economy): Response
    {
        $owner = $this->getUser();
        if (!$owner instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $view = $economy->overviewFor($owner);
        if ($view === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('planet/overview.html.twig', ['view' => $view]);
    }

    #[Route('/planet/build/{buildingId}', name: 'app_planet_enqueue', requirements: ['buildingId' => '\\d+'], methods: ['POST'])]
    public function enqueue(int $buildingId, Request $request, EconomyApplicationService $economy): Response
    {
        $owner = $this->getUser();
        if (!$owner instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $data = $request->request->all();
        $expectedText = $data['expected_target'] ?? null;
        $commandToken = $data['command_token'] ?? null;
        $csrfToken = $data['_token'] ?? null;
        if (!is_string($expectedText) || preg_match('/\\A[1-9][0-9]{0,2}\\z/D', $expectedText) !== 1
            || (int) $expectedText > 255 || !is_string($commandToken) || !is_string($csrfToken)
            || !$this->isCsrfTokenValid('build_'.$buildingId, $csrfToken)) {
            $this->addFlash('error', 'That construction request could not be validated. Please retry from the planet page.');

            return $this->redirectToRoute('app_planet');
        }

        try {
            $result = $economy->enqueueForOwner($owner, $buildingId, (int) $expectedText, $commandToken);
        } catch (IdempotencyConflict) {
            $this->addFlash('error', 'That construction action was already used for a different request. Refresh the planet page.');

            return $this->redirectToRoute('app_planet');
        }

        if (!$result->accepted) {
            $this->addFlash('error', self::rejectionMessage($result->rejection?->code));
        } elseif (!$result->replayed) {
            $this->addFlash('success', 'Construction request added to the queue. Waiting work is paid when it starts.');
        } else {
            $this->addFlash('notice', 'This construction action was already processed. No additional resources were charged.');
        }

        return $this->redirectToRoute('app_planet');
    }

    private static function rejectionMessage(?RejectionCode $code): string
    {
        return match ($code) {
            RejectionCode::ClockRegression => 'The planet could not be updated. Please retry.',
            RejectionCode::LevelLimit => 'That building has reached its maximum level.',
            RejectionCode::QueueFull => 'The construction queue is full.',
            RejectionCode::FieldsFull => 'No planet fields remain for this construction.',
            RejectionCode::StaleTarget => 'The planet changed before this request was processed. Refresh and try again.',
            RejectionCode::Unaffordable => 'There are not enough resources to start that construction.',
            RejectionCode::TimestampRange => 'That construction exceeds the supported time range.',
            RejectionCode::DuplicatePendingToken => 'That construction action was already processed.',
            RejectionCode::InvalidCommandToken, null => 'That construction request could not be processed. Refresh and try again.',
        };
    }
}
