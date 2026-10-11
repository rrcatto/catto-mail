<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Connect\ConnectService;
use App\Dashboard\ClientAccess;
use App\Security\ClientVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The applications connected to a client (specification 2.12), in its own dashboard. Every
 * member sees them: the application's name, where its webhooks go, its API key's prefix and
 * last use, who approved it. Client admins (or PLATFORM.CLIENT.MANAGE) revoke a connection,
 * which revokes its API key and removes its webhook endpoint, through the audited
 * ConnectService. A connection is made only on the connect page, by the application's own
 * request. Another client's connection is a 404.
 */
#[Route('/dashboard/c/{clientId}/connections', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientConnectionController extends AbstractController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ConnectService $connect,
    ) {
    }

    #[Route('', name: 'dashboard_client_connections', methods: ['GET'])]
    public function index(string $clientId): Response
    {
        $client = $this->access->client($clientId);

        return $this->render('dashboard/client/connections.html.twig', ['client' => $client, 'section' => 'connections',
            'connections' => $this->connect->connections($clientId), 'may_manage' => $this->access->may(ClientVoter::ADMIN, $client)]);
    }

    #[Route('/{id}/revoke', name: 'dashboard_client_connection_revoke', methods: ['POST'])]
    public function revoke(string $clientId, string $id, Request $request): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $connection = $this->connect->find($client, $id) ?? throw new NotFoundHttpException('Not found.');
        $this->connect->revoke($connection, AuditActor::user($this->access->user()), 'client');
        $this->addFlash('success', \sprintf('%s is disconnected: its API key is revoked and its webhook endpoint removed.', $connection->getApplicationName()));

        return $this->redirectToRoute('dashboard_client_connections', ['clientId' => $clientId]);
    }
}
