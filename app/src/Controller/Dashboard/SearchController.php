<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Dashboard\ClientAccess;
use App\Dashboard\Search;
use App\Security\ClientVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The top bar's search (App\Dashboard\Search): the operator console searches the
 * installation within the user's permission keys; a client workspace searches only that
 * client (ClientAccess: a client the user may not see is a 404). GET only, nothing changes.
 */
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly Search $search,
        private readonly ClientAccess $access,
    ) {
    }

    #[Route('/dashboard/search', name: 'dashboard_search', methods: ['GET'])]
    public function operator(Request $request): Response
    {
        if (!$this->access->user()->isPlatformUser()) {
            throw $this->createAccessDeniedException('The installation search is for operators.');
        }
        $q = mb_substr(trim($request->query->getString('q')), 0, 200);

        return $this->render('dashboard/search.html.twig', ['section' => 'search', 'q' => $q, 'results' => $this->search->operator($q)]);
    }

    #[Route('/dashboard/c/{clientId}/search', name: 'dashboard_client_search', methods: ['GET'], requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
    public function client(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId, ClientVoter::VIEW);
        $q = mb_substr(trim($request->query->getString('q')), 0, 200);

        return $this->render('dashboard/search.html.twig', ['section' => 'search', 'client' => $client, 'q' => $q,
            'results' => $this->search->client($client, $q)]);
    }
}
