<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every error under /v1 becomes an RFC 9457 problem. Unexpected exceptions are
 * logged and answered with a generic 500 that reveals nothing internal.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class ApiExceptionSubscriber
{
    public function __construct(
        private readonly ProblemResponder $responder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!ApiPath::matches($request->getPathInfo())) {
            return;
        }
        $e = $event->getThrowable();
        $problem = match (true) {
            $e instanceof ApiProblem => $e,
            $e instanceof HttpExceptionInterface => match ($e->getStatusCode()) {
                400 => ApiProblem::badRequest('Malformed request.'),
                404 => ApiProblem::notFound(),
                405 => ApiProblem::methodNotAllowed(),
                413 => ApiProblem::payloadTooLarge(0),
                default => new ApiProblem($e->getStatusCode(), 'http-error', 'HTTP error'),
            },
            default => null,
        };
        if (null === $problem) {
            $this->logger->error('Unhandled API exception', ['exception' => $e, 'path' => $request->getPathInfo()]);
            $problem = ApiProblem::internal();
        }
        $event->setResponse($this->responder->respond($problem, $request));
    }
}
