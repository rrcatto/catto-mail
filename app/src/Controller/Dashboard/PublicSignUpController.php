<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Client\PublicSignUp;
use App\Connect\ConnectRequest;
use App\Domain\DomainRuleViolation;
use App\Security\LoginLinkAuthenticator;
use App\Security\LoginLinkService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public sign-up page (owner decision 2026-10-11), only while APP_PUBLIC_ONBOARDING_ENABLED is
 * true: otherwise every route here is 404.
 *
 *   GET  /dashboard/sign-up          the form: organisation, email address, a honeypot field
 *   POST /dashboard/sign-up          CSRF; at most 10 submissions an hour per client IP; a filled
 *                                    honeypot is answered like a success and does nothing; the
 *                                    answer never says whether an address already has an account
 *   GET  /dashboard/sign-up/verify   the emailed link: shows what will be created (and the policy
 *                                    to accept); changes nothing (HEAD: 204 for mail scanners)
 *   POST /dashboard/sign-up/verify   CSRF; per client IP and per address limits; opens the
 *                                    account (PublicSignUp::complete), signs the person in and
 *                                    leads to the client's overview, which shows the next steps
 *
 * The verification page and its confirmation share 20 attempts an hour per client IP; the
 * confirmation also 5 an hour per address. Nothing secret (the token) is logged.
 *
 * Someone who came from the connect page (specification 2.12) carries `return_to`, a connect
 * path and nothing else (ConnectRequest::returnPath): it travels with the form into the emailed
 * link, and confirming leads back to the connect step instead of the client's overview.
 */
#[Route('/dashboard/sign-up')]
final class PublicSignUpController extends AbstractController
{
    public const CSRF_FORM = 'sign-up';
    public const CSRF_CONFIRM = 'sign-up-confirm';
    public const HONEYPOT = 'website';

    public function __construct(
        private readonly PublicSignUp $signUp,
        private readonly LoginLinkService $links,
        private readonly RateLimiterFactoryInterface $publicSignUpLimiter,
        private readonly RateLimiterFactoryInterface $publicSignUpVerifyLimiter,
        private readonly RateLimiterFactoryInterface $publicSignUpAddressLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'dashboard_sign_up', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->assertOpen();
        $returnTo = ConnectRequest::returnPath($request->query->getString('return_to'));
        if (null !== $this->getUser()) {
            return null !== $returnTo ? new RedirectResponse($returnTo) : $this->redirectToRoute('dashboard_home');
        }

        return $this->render('dashboard/sign_up/form.html.twig', ['organisation' => '', 'email' => '', 'error' => null, 'return_to' => $returnTo]);
    }

    #[Route('', name: 'dashboard_sign_up_request', methods: ['POST'])]
    public function request(Request $request): Response
    {
        $this->assertOpen();
        $returnTo = ConnectRequest::returnPath($request->request->getString('return_to'));
        $back = null === $returnTo ? [] : ['return_to' => $returnTo];
        if (!$this->isCsrfTokenValid(self::CSRF_FORM, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'The form expired. Please try again.');

            return $this->redirectToRoute('dashboard_sign_up', $back);
        }
        if (!$this->publicSignUpLimiter->create($this->ipKey($request))->consume()->isAccepted()) {
            return $this->tooMany();
        }
        if ('' !== $request->request->getString(self::HONEYPOT)) {
            $this->logger->info('Sign-up form: honeypot field filled; nothing sent.');

            return $this->redirectToRoute('dashboard_sign_up_sent');
        }
        $organisation = $request->request->getString('organisation');
        $email = $request->request->getString('email');
        try {
            $this->signUp->request($organisation, $email, $request->getClientIp(), $returnTo);
        } catch (DomainRuleViolation $e) {
            return $this->render('dashboard/sign_up/form.html.twig', ['organisation' => mb_substr($organisation, 0, 200),
                'email' => mb_substr($email, 0, 320), 'error' => $e->getMessage(), 'return_to' => $returnTo], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_sign_up', $back);
        }

        return $this->redirectToRoute('dashboard_sign_up_sent');
    }

    #[Route('/sent', name: 'dashboard_sign_up_sent', methods: ['GET'])]
    public function sent(): Response
    {
        $this->assertOpen();

        return $this->render('dashboard/sign_up/sent.html.twig', ['minutes' => intdiv((int) $this->getParameter('app.login_link_ttl_seconds'), 60)]);
    }

    #[Route('/verify', name: 'dashboard_sign_up_verify', methods: ['GET', 'HEAD'])]
    public function verify(Request $request): Response
    {
        $this->assertOpen();
        if ($request->isMethod('HEAD')) {
            return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        }
        if (!$this->publicSignUpVerifyLimiter->create($this->ipKey($request))->consume()->isAccepted()) {
            return $this->tooMany();
        }
        $token = $request->query->getString('token');

        return $this->confirmPage($token, $this->links->signUpLink($token), null);
    }

    #[Route('/verify', name: 'dashboard_sign_up_confirm', methods: ['POST'])]
    public function confirm(Request $request, Security $security): Response
    {
        $this->assertOpen();
        $token = $request->request->getString('token');
        if (!$this->isCsrfTokenValid(self::CSRF_CONFIRM, $request->request->getString('_csrf_token'))) {
            $this->addFlash('error', 'The form expired. Open the link from the email again.');

            return $this->redirectToRoute('dashboard_sign_up');
        }
        $link = $this->links->signUpLink($token);
        if (!$this->publicSignUpVerifyLimiter->create($this->ipKey($request))->consume()->isAccepted()
            || (null !== $link && !$this->publicSignUpAddressLimiter->create(hash('sha256', strtolower($link['email'])))->consume()->isAccepted())) {
            return $this->tooMany();
        }
        try {
            $opened = $this->signUp->complete($token, '1' === $request->request->getString('accept_policy'));
        } catch (DomainRuleViolation $e) {
            return $this->confirmPage($token, $link, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {   // the same address confirmed twice at once
            return $this->confirmPage($token, $link, 'This address already belongs to an account. Sign in instead.', Response::HTTP_CONFLICT);
        }
        $security->login($opened['user'], LoginLinkAuthenticator::class, 'dashboard');
        $request->getSession()->getFlashBag()->clear();
        $returnTo = ConnectRequest::returnPath($opened['return_path']);
        if (null !== $returnTo) {   // signed up from the connect page: back to the connect step
            $this->addFlash('success', \sprintf('Welcome to Catto Mail. The account of %s is open.', $opened['client']->getCompanyName()));

            return new RedirectResponse($returnTo);
        }
        $this->addFlash('success', \sprintf('Welcome to Catto Mail. The account of %s is open; the steps below get you sending.', $opened['client']->getCompanyName()));

        return new RedirectResponse($this->generateUrl('dashboard_client_overview', ['clientId' => $opened['client']->getId()->toRfc4122()]));
    }

    /**
     * The link's page: what will be created, or why it cannot be (an invalid link, an address that
     * already has an account).
     *
     * @param array{email: string, organisation: string}|null $link
     */
    private function confirmPage(string $token, ?array $link, ?string $error, int $status = Response::HTTP_OK): Response
    {
        $state = null === $link ? 'invalid' : ($this->signUp->isTaken($link['email']) ? 'taken' : 'confirm');
        $error ??= match ($state) {
            'invalid' => 'This confirmation link is not valid any more: it was used, it expired, or it is incomplete. Sign up again to get a new one.',
            'taken' => 'This address already belongs to an account. Sign in instead.',
            default => null,
        };
        $response = $this->render('dashboard/sign_up/confirm.html.twig', ['state' => $state, 'token' => 'confirm' === $state ? $token : '',
            'link' => $link, 'error' => $error, 'policy' => $this->signUp->policyVersion(), 'limits' => $this->signUp->starterLimits()],
            new Response('', $status));
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

    private function assertOpen(): void
    {
        if (!$this->signUp->enabled()) {
            throw new NotFoundHttpException('Not found.');
        }
    }
}
