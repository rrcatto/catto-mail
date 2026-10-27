<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Client\Invitations;
use App\Config\Installation;
use App\Domain\DomainRuleViolation;
use App\Entity\User;
use App\Security\LoginLinkAuthenticator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An operator's invitation link (owner decision 2026-10-27, App\Client\Invitations): accounts
 * open by invitation while the public sign-up is closed.
 *
 *   GET  /dashboard/invitation?token=   the invitation: a visitor gives the organisation and
 *                                       their address; someone signed in only the organisation.
 *                                       Changes nothing (HEAD: 204 for mail scanners).
 *   POST /dashboard/invitation          CSRF; a visitor: emails the confirmation link (10 an hour
 *                                       per client address, the honeypot, the same answer
 *                                       whether the address has an account or not); someone
 *                                       signed in: opens the account at once
 *   GET  /dashboard/invitation/confirm  the confirmation link's page: what will be opened, and the
 *                                       policy to accept when the invitation requires it
 *   POST /dashboard/invitation/confirm  CSRF; opens the account, signs the person in and leads to
 *                                       the account's start page
 *
 * The tokens travel in the query or the form body only, and nginx logs neither.
 */
#[Route('/dashboard/invitation')]
final class InvitationController extends AbstractController
{
    public const CSRF_FORM = 'invitation';
    public const CSRF_CONFIRM = 'invitation-confirm';

    public function __construct(
        private readonly Invitations $invitations,
        private readonly RateLimiterFactoryInterface $publicSignUpLimiter,
        private readonly RateLimiterFactoryInterface $publicSignUpVerifyLimiter,
        private readonly RateLimiterFactoryInterface $publicSignUpAddressLimiter,
        private readonly LoggerInterface $logger,
        private readonly Installation $installation,
    ) {
    }

    #[Route('', name: 'dashboard_invitation', methods: ['GET', 'HEAD'])]
    public function page(Request $request): Response
    {
        if ($request->isMethod('HEAD')) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        }
        if (!$this->publicSignUpVerifyLimiter->create($this->ipKey($request))->consume()->isAccepted()) {
            return $this->tooMany();
        }

        return $this->form($request->query->getString('token'), '', '', null);
    }

    #[Route('', name: 'dashboard_invitation_request', methods: ['POST'])]
    public function request(Request $request): Response
    {
        $token = $request->request->getString('token');
        if (!$this->isCsrfTokenValid(self::CSRF_FORM, $request->request->getString('_csrf_token'))) {
            return $this->form($token, '', '', 'The form expired. Please try again.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->publicSignUpLimiter->create($this->ipKey($request))->consume()->isAccepted()) {
            return $this->tooMany();
        }
        $organisation = $request->request->getString('organisation');
        $user = $this->getUser();
        try {
            if ($user instanceof User) {
                $client = $this->invitations->openForUser($token, $organisation, '1' === $request->request->getString('accept_policy'), $user);
                $this->addFlash('success', \sprintf('The account of %s is open. The steps below get you sending.', $client->getCompanyName()));

                return $this->redirectToRoute('dashboard_client_overview', ['clientId' => $client->getId()->toRfc4122()]);
            }
            if ('' !== $request->request->getString(PublicSignUpController::HONEYPOT)) {
                $this->logger->info('Invitation form: honeypot field filled; nothing sent.');

                return $this->redirectToRoute('dashboard_invitation_sent');
            }
            $this->invitations->request($token, $organisation, $request->request->getString('email'), $request->getClientIp());
        } catch (DomainRuleViolation $e) {
            return $this->form($token, $organisation, $request->request->getString('email'), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->form($token, $organisation, $request->request->getString('email'), $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->redirectToRoute('dashboard_invitation_sent');
    }

    #[Route('/sent', name: 'dashboard_invitation_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('dashboard/invitation/sent.html.twig', ['minutes' => intdiv((int) $this->getParameter('app.login_link_ttl_seconds'), 60)]);
    }

    #[Route('/confirm', name: 'dashboard_invitation_confirm', methods: ['GET', 'HEAD'])]
    public function confirmPage(Request $request): Response
    {
        if ($request->isMethod('HEAD')) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        }
        if (!$this->publicSignUpVerifyLimiter->create($this->ipKey($request))->consume()->isAccepted()) {
            return $this->tooMany();
        }

        return $this->confirmation($request->query->getString('token'), null);
    }

    #[Route('/confirm', name: 'dashboard_invitation_complete', methods: ['POST'])]
    public function complete(Request $request, Security $security): Response
    {
        $token = $request->request->getString('token');
        if (!$this->isCsrfTokenValid(self::CSRF_CONFIRM, $request->request->getString('_csrf_token'))) {
            return $this->confirmation($token, 'The form expired. Please confirm again.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $found = $this->invitations->confirmation($token);
        if (!$this->publicSignUpVerifyLimiter->create($this->ipKey($request))->consume()->isAccepted()
            || (null !== $found && !$this->publicSignUpAddressLimiter->create(hash('sha256', strtolower($found['link']['email'])))->consume()->isAccepted())) {
            return $this->tooMany();
        }
        try {
            $opened = $this->invitations->complete($token, '1' === $request->request->getString('accept_policy'));
        } catch (DomainRuleViolation $e) {
            return $this->confirmation($token, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {   // the same address confirmed twice at once
            return $this->confirmation($token, 'This address already has an account. Sign in, then open the invitation link again.', Response::HTTP_CONFLICT);
        }
        $security->login($opened['user'], LoginLinkAuthenticator::class, 'dashboard');
        $request->getSession()->getFlashBag()->clear();
        $this->addFlash('success', \sprintf('Welcome to %s. The account of %s is open; the steps below get you sending.',
            $this->installation->siteName(), $opened['client']->getCompanyName()));

        return $this->redirectToRoute('dashboard_client_overview', ['clientId' => $opened['client']->getId()->toRfc4122()]);
    }

    private function form(string $token, string $organisation, string $email, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $invitation = $this->invitations->find($token);
        $response = $this->render('dashboard/invitation/form.html.twig', ['invitation' => $invitation, 'token' => null === $invitation ? '' : $token,
            'organisation' => mb_substr($organisation, 0, 200), 'email' => mb_substr($email, 0, 320), 'error' => $error,
            'policy' => $this->invitations->policyVersion()], new Response('', null === $invitation ? Response::HTTP_GONE : $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function confirmation(string $token, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $found = $this->invitations->confirmation($token);
        $response = $this->render('dashboard/invitation/confirm.html.twig', ['found' => $found, 'token' => null === $found ? '' : $token,
            'error' => $error, 'policy' => $this->invitations->policyVersion()], new Response('', null === $found ? Response::HTTP_GONE : $status));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function tooMany(): Response
    {
        return $this->render('dashboard/sign_up/too_many.html.twig', [], new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '3600']));
    }

    private function ipKey(Request $request): string
    {
        return hash('sha256', (string) $request->getClientIp());
    }
}
