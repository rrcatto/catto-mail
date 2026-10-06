<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\WebhookEndpoint;
use App\Enum\WebhookEndpointStatus;
use App\Enum\WebhookEventType;
use App\Security\ClientVoter;
use App\Webhook\WebhookEndpointService;
use App\Webhook\WebhookTestService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Webhook endpoints of a client in the dashboard (Phase 7). Viewers see endpoints
 * and deliveries; client admins (or PLATFORM.CLIENT.MANAGE) create, edit,
 * enable/disable, rotate secrets and send test events, through the audited
 * WebhookEndpointService / WebhookTestService. A raw signing secret is shown once,
 * in the response to the request that created or rotated it, and is never
 * retrievable afterwards. Another client's endpoint is a 404.
 */
#[Route('/dashboard/c/{clientId}/webhooks', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientWebhookController extends AbstractController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientReadModel $read,
        private readonly WebhookEndpointService $endpoints,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'dashboard_client_webhooks', methods: ['GET'])]
    public function index(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId);
        $filters = ClientDashboardController::filters($request, ['status', 'endpoint']);
        $listing = Listing::fromRequest($request, array_keys(ClientReadModel::DELIVERY_SORTS), 'created');

        return $this->render('dashboard/client/webhooks.html.twig', [
            'client' => $client, 'section' => 'webhooks', 'endpoints' => $this->read->webhookEndpoints($clientId),
            'deliveries' => $this->read->webhookDeliveries($clientId, $filters, $listing), 'filters' => $filters, 'listing' => $listing,
            'event_types' => self::subscribable(), 'may_manage' => $this->access->may(ClientVoter::ADMIN, $client),
        ]);
    }

    #[Route('', name: 'dashboard_client_webhook_create', methods: ['POST'])]
    public function create(string $clientId, Request $request): Response
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        try {
            [$endpoint, $secret] = $this->endpoints->create($client, trim($request->request->getString('url')), self::types($request), $this->actor());
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_client_webhooks', ['clientId' => $clientId]);
        }

        return $this->secret($client, $endpoint, $secret, 'Webhook endpoint created');
    }

    #[Route('/{id}', name: 'dashboard_client_webhook_update', methods: ['POST'])]
    public function update(string $clientId, string $id, Request $request): RedirectResponse
    {
        [$client, $endpoint] = $this->endpoint($clientId, $id);
        $this->assertCsrf($request);
        try {
            $this->endpoints->update($endpoint, trim($request->request->getString('url')), self::types($request), $this->actor());
            $status = WebhookEndpointStatus::tryFrom($request->request->getString('status'));
            if (null !== $status) {
                $this->endpoints->setStatus($endpoint, $status, $this->actor());
            }
            $this->addFlash('success', 'Webhook endpoint saved.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_webhooks', ['clientId' => $clientId]);
    }

    #[Route('/{id}/rotate', name: 'dashboard_client_webhook_rotate', methods: ['POST'])]
    public function rotate(string $clientId, string $id, Request $request): Response
    {
        [$client, $endpoint] = $this->endpoint($clientId, $id);
        $this->assertCsrf($request);

        return $this->secret($client, $endpoint, $this->endpoints->rotateSecret($endpoint, $this->actor()), 'Signing secret rotated');
    }

    #[Route('/{id}/test', name: 'dashboard_client_webhook_test', methods: ['POST'])]
    public function test(string $clientId, string $id, Request $request, WebhookTestService $tests): RedirectResponse
    {
        [$client, $endpoint] = $this->endpoint($clientId, $id);
        $this->assertCsrf($request);
        $eventId = $tests->request($client, $endpoint, $this->actor());
        $this->addFlash(null === $eventId ? 'error' : 'success', null === $eventId
            ? 'This endpoint is disabled; enable it to send a test event.'
            : \sprintf('Test event %s recorded; the webhook worker delivers it shortly (see the deliveries below).', $eventId->toRfc4122()));

        return $this->redirectToRoute('dashboard_client_webhooks', ['clientId' => $clientId]);
    }

    private function secret(Client $client, WebhookEndpoint $endpoint, string $secret, string $title): Response
    {
        $response = $this->render('dashboard/client/webhook_secret.html.twig', [
            'client' => $client, 'section' => 'webhooks', 'endpoint' => $endpoint, 'secret' => $secret, 'title' => $title]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array{0: Client, 1: WebhookEndpoint} */
    private function endpoint(string $clientId, string $id): array
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $endpoint = ClientReadModel::isUuid($id)
            ? $this->em->getRepository(WebhookEndpoint::class)->findOneBy(['id' => Uuid::fromString($id), 'client' => $client]) : null;

        return [$client, $endpoint ?? throw new NotFoundHttpException('Not found.')];
    }

    private function actor(): AuditActor
    {
        return AuditActor::user($this->access->user());
    }

    /** @return list<string> */
    private static function types(Request $request): array
    {
        return array_values(array_filter($request->request->all('event_types'), 'is_string'));
    }

    /** @return list<string> */
    public static function subscribable(): array
    {
        return array_values(array_filter(array_map(static fn (WebhookEventType $t): string => $t->value, WebhookEventType::cases()),
            static fn (string $t): bool => 'webhook.test' !== $t));
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
