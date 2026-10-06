<?php

declare(strict_types=1);

namespace App\Controller;

use App\Tracking\TrackingRecorder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated engagement-tracking endpoints (spec
 * message_tracking.engagement_tracking; routes as Go writes them):
 *
 *   GET /t/o/{token}.gif         recorded open: always the same 1x1 GIF
 *   GET /t/c/{token}/{index}     recorded click: 302 to the stored target, or 404
 *
 * No session, cookie or JavaScript; no recipient, client or message data in any
 * response; an unknown, expired, malformed or ineligible token gets exactly the
 * same answer as a valid one that is not recorded (pixel) or a plain 404 (click).
 * Responses are never cacheable, so every real fetch reaches the application. HEAD
 * requests are answered but never recorded. Requests over the per-address rate
 * limit are still answered, without a database write.
 */
final class TrackingController
{
    /** The smallest transparent 1x1 GIF (43 bytes). */
    private const PIXEL = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    private const HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private readonly TrackingRecorder $recorder,
        private readonly RateLimiterFactoryInterface $trackingLimiter,
    ) {
    }

    #[Route('/t/o/{token}.gif', name: 'tracking_open', requirements: ['token' => '[^/.]{1,128}'], methods: ['GET', 'HEAD'])]
    public function open(string $token, Request $request): Response
    {
        if ($request->isMethod('GET')) {
            $this->recorder->recordOpen($token, $this->mayWrite($request));
        }

        return new Response(self::PIXEL, 200, ['Content-Type' => 'image/gif'] + self::HEADERS);
    }

    #[Route('/t/c/{token}/{index}', name: 'tracking_click', requirements: ['token' => '[^/]{1,128}', 'index' => '[0-9]{1,6}'], methods: ['GET', 'HEAD'])]
    public function click(string $token, string $index, Request $request): Response
    {
        $target = $this->recorder->resolveClick($token, (int) $index, $request->isMethod('GET') && $this->mayWrite($request));
        if (null === $target) {
            return self::notFound();
        }

        // The target is exactly the stored message_links.target_url (absolute http/https).
        return new RedirectResponse($target, 302, self::HEADERS);
    }

    /**
     * Any other URL under /t/ (malformed index, extra segments, ...): the same plain
     * 404 as an unknown click, returned rather than thrown, so the router's
     * "No route found" error (which logs the full URL, token included) never fires.
     */
    #[Route('/t/{rest}', name: 'tracking_fallback', requirements: ['rest' => '.*'], methods: ['GET', 'HEAD'], priority: -100)]
    public function fallback(): Response
    {
        return self::notFound();
    }

    private static function notFound(): Response
    {
        return new Response("Not found\n", 404, ['Content-Type' => 'text/plain; charset=utf-8'] + self::HEADERS);
    }

    private function mayWrite(Request $request): bool
    {
        return $this->trackingLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted();
    }
}
