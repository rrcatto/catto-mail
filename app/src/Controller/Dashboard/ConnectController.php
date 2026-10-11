<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Connect\ConnectRequest;
use App\Connect\ConnectService;
use App\Dashboard\SecurityHeadersSubscriber;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The connect page (specification 2.12): an application sends a person here to be connected
 * with one approval, in an authorisation-code flow with PKCE.
 *
 *   GET  /dashboard/connect   ?application_name, redirect_uri, webhook_url, state,
 *                             code_challenge, code_challenge_method=S256. Changes nothing. A
 *                             request that is incomplete or refused (a redirect or webhook URL
 *                             that is not allowed) gets an explanation and is never redirected
 *                             anywhere. A visitor is shown what is asked and offered sign-in and,
 *                             while APP_PUBLIC_ONBOARDING_ENABLED is true, sign-up; both lead
 *                             back here. A signed-in person chooses one of their workspaces.
 *   POST /dashboard/connect   CSRF; the same request again with the chosen client and the
 *                             decision. Approving (a client admin, an active client, room under
 *                             the key and endpoint limits) answers with a redirect to the
 *                             redirect URI carrying a single-use code and the state; declining
 *                             with error=access_denied and the state. Nothing secret is ever in
 *                             a redirect: the application exchanges the code server to server.
 */
#[Route(ConnectRequest::PATH)]
final class ConnectController extends AbstractController
{
    public const CSRF_ID = 'connect';

    public function __construct(
        private readonly ConnectService $connect,
        private readonly RateLimiterFactoryInterface $connectPageLimiter,
    ) {
    }

    #[Route('', name: 'dashboard_connect', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $connect = $this->read($request, $request->query->all());
        if ($connect instanceof Response) {
            return $connect;
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->render('dashboard/connect/sign_in.html.twig', ['connect' => $connect, 'return_to' => $connect->path()]);
        }

        return $this->approval($request, $connect, $user, null);
    }

    #[Route('', name: 'dashboard_connect_decide', methods: ['POST'])]
    public function decide(Request $request): Response
    {
        $connect = $this->read($request, $request->request->all());
        if ($connect instanceof Response) {
            return $connect;
        }
        $user = $this->getUser();
        if (!$user instanceof User) {   // the session ended on the page: sign in again, then decide
            return new RedirectResponse($connect->path(), Response::HTTP_SEE_OTHER);
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->request->getString('_token'))) {
            $this->addFlash('error', 'The form expired. Please decide again.');

            return new RedirectResponse($connect->path(), Response::HTTP_SEE_OTHER);
        }
        $decision = $request->request->getString('decision');
        if ('deny' === $decision) {
            return new RedirectResponse($connect->callback(['error' => 'access_denied', 'error_description' => 'The request was declined.',
                'state' => $connect->state]), Response::HTTP_SEE_OTHER);
        }
        if ('approve' !== $decision) {
            return $this->approval($request, $connect, $user, 'Choose Connect or Cancel.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $code = $this->connect->approve($user, $request->request->getString('client_id'), $connect);
        } catch (DomainRuleViolation $e) {
            return $this->approval($request, $connect, $user, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Only the single-use code and the application's own state: the credentials follow by the exchange.
        return new RedirectResponse($connect->callback(['code' => $code, 'state' => $connect->state]), Response::HTTP_SEE_OTHER);
    }

    /**
     * The validated request, or the answer to give instead: too many requests, or what is wrong
     * with the request (never a redirect to an address that was not accepted).
     *
     * @param array<array-key, mixed> $input
     */
    private function read(Request $request, array $input): ConnectRequest|Response
    {
        if (!$this->connectPageLimiter->create(hash('sha256', (string) $request->getClientIp()))->consume()->isAccepted()) {
            return $this->render('dashboard/connect/invalid.html.twig', ['title' => 'Too many requests',
                'error' => 'Too many connect requests came from your address. Try again in an hour.'],
                new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '3600']));
        }
        try {
            return $this->connect->request($input);
        } catch (DomainRuleViolation $e) {
            return $this->render('dashboard/connect/invalid.html.twig', ['title' => 'This connect request cannot be used', 'error' => $e->getMessage()],
                new Response('', Response::HTTP_BAD_REQUEST));
        }
    }

    private function approval(Request $request, ConnectRequest $connect, User $user, ?string $error, int $status = Response::HTTP_OK): Response
    {
        // The form's answer is a redirect to the application: its origin joins this page's form-action.
        SecurityHeadersSubscriber::allowFormAction($request, $connect->redirectOrigin());
        $workspaces = $this->connect->workspaces($user, $connect);

        return $this->render('dashboard/connect/approve.html.twig', ['connect' => $connect, 'workspaces' => $workspaces, 'error' => $error,
            'eligible' => \count(array_filter($workspaces, static fn (array $w): bool => null === $w['obstacle'])),
            'chosen' => $request->request->getString('client_id'), 'events' => ConnectService::EVENTS,
            'minutes' => intdiv(ConnectService::CODE_TTL_SECONDS, 60)], new Response('', $status));
    }
}
