<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientAccountReadModel;
use App\Dashboard\ClientReadModel;
use App\Domain\DomainRuleViolation;
use App\Entity\ApiKey;
use App\Enum\PolicyAcceptanceSource;
use App\Security\ApiKeyManager;
use App\Security\ClientVoter;
use App\Util\InstallationTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * A client's API keys in its own dashboard (Phase 9). Every member sees the key
 * metadata (name, prefix, created, last used, expiry, revocation; never a hash). Client
 * admins (or PLATFORM.CLIENT_KEY.MANAGE) create keys - the raw key is shown once, in the
 * response to the creating request - and revoke them, through the audited ApiKeyManager.
 * Rotation without downtime: create the replacement, deploy it, check that it is used,
 * revoke the old key. Also the client admin's acceptance of the service policy.
 */
#[Route('/dashboard/c/{clientId}', requirements: ['clientId' => '[0-9a-fA-F-]{36}'])]
final class ClientApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly ClientAccountReadModel $account,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api-keys', name: 'dashboard_client_api_keys', methods: ['GET'])]
    public function index(string $clientId): Response
    {
        $client = $this->access->client($clientId);

        return $this->render('dashboard/client/api_keys.html.twig', ['client' => $client, 'section' => 'api_keys',
            'keys' => $this->account->apiKeys($clientId), 'may_manage' => $this->access->may(ClientVoter::KEYS, $client),
            'limits' => $this->account->limits($client)]);
    }

    #[Route('/api-keys', name: 'dashboard_client_api_key_create', methods: ['POST'])]
    public function create(string $clientId, Request $request, ApiKeyManager $keys, InstallationTime $time): Response
    {
        $client = $this->access->client($clientId, ClientVoter::KEYS);
        $this->assertCsrf($request);
        try {
            [$key, $raw] = $keys->create($client, $request->request->getString('name'), $this->actor(), OperatorClientController::expiry($request, $time->zone));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_client_api_keys', ['clientId' => $clientId]);
        }
        $response = $this->render('dashboard/api_key_secret.html.twig', ['client' => $client, 'section' => 'api_keys', 'key' => $key,
            'raw' => $raw, 'back' => $this->generateUrl('dashboard_client_api_keys', ['clientId' => $clientId]), 'client_name' => $client->getCompanyName()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/api-keys/{keyId}/revoke', name: 'dashboard_client_api_key_revoke', methods: ['POST'])]
    public function revoke(string $clientId, string $keyId, Request $request, ApiKeyManager $keys): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::KEYS);
        $this->assertCsrf($request);
        $key = ClientReadModel::isUuid($keyId) ? $this->em->getRepository(ApiKey::class)->findOneBy(['id' => Uuid::fromString($keyId), 'client' => $client]) : null;
        if (null === $key) {
            throw new NotFoundHttpException('Not found.');
        }
        $keys->revoke($key, $this->actor());
        $this->addFlash('success', \sprintf('API key %s… revoked. Requests with it now fail with 401.', $key->getKeyPrefix()));

        return $this->redirectToRoute('dashboard_client_api_keys', ['clientId' => $clientId]);
    }

    #[Route('/policy/accept', name: 'dashboard_client_policy_accept', methods: ['POST'])]
    public function acceptPolicy(string $clientId, Request $request, ClientLifecycle $lifecycle): RedirectResponse
    {
        $client = $this->access->client($clientId, ClientVoter::ADMIN);
        $this->assertCsrf($request);
        try {
            $lifecycle->recordPolicyAcceptance($client, $request->request->getString('policy_version'), PolicyAcceptanceSource::ClientDashboard,
                $this->actor(), $this->access->user());
            $this->addFlash('success', 'Service policy accepted.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_client_overview', ['clientId' => $clientId]);
    }

    private function actor(): AuditActor
    {
        return AuditActor::user($this->access->user());
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
