<?php

declare(strict_types=1);

namespace App\Dashboard;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Response headers for the human dashboard (/dashboard). Pages are private and
 * never cached, cannot be framed, and run only same-origin scripts plus the
 * inline import map carrying this request's nonce. The headers are confined to
 * /dashboard: the public tracking endpoints (/t/...) set their own minimal
 * headers and the /v1 API is JSON.
 */
final class SecurityHeadersSubscriber
{
    private const ATTRIBUTE = '_smarthost_csp_nonce';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** The CSP nonce of the current request (created on first use). */
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('No request.');
        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    public static function isDashboard(Request $request): bool
    {
        return 1 === preg_match('#^/dashboard(/|$)#', $request->getPathInfo());
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !self::isDashboard($event->getRequest())) {
            return;
        }
        $nonce = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        $script = "'self'".(\is_string($nonce) ? " 'nonce-$nonce'" : '');
        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "default-src 'none'; script-src $script; style-src 'self'; img-src 'self' data:; "
            ."connect-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (!$headers->has('Content-Disposition')) {
            $headers->set('Cache-Control', 'no-store, private');
        }
    }
}
