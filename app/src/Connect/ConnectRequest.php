<?php

declare(strict_types=1);

namespace App\Connect;

use App\Domain\DomainRuleViolation;
use App\Security\LoginLinkService;

/**
 * What an application asks for when it sends a person to the connect page
 * (specification 2.12): its name, where to send the person back to, where its webhooks go,
 * its own state value and its PKCE code challenge (S256). Nothing here is secret, and nothing
 * is stored before a client admin approves: the request travels in the address of the page
 * (through sign-in or sign-up as the link's return path) and comes back with the approval.
 *
 * The syntax is checked here; App\Connect\ConnectService::request() also puts the webhook URL
 * through the webhook target guard.
 */
final class ConnectRequest
{
    public const PATH = '/dashboard/connect';
    public const PARAMETERS = ['application_name', 'redirect_uri', 'webhook_url', 'state', 'code_challenge', 'code_challenge_method'];
    public const MAX_URL_LENGTH = 2000;
    private const MAX_PATH_LENGTH = 4000;

    private function __construct(
        public readonly string $applicationName,
        public readonly string $redirectUri,
        public readonly string $webhookUrl,
        public readonly string $state,
        public readonly string $codeChallenge,
    ) {
    }

    /**
     * @param array<array-key, mixed> $input       the query of the connect page, or the fields of its form
     * @param bool                    $httpAllowed plain http is accepted (development and test only)
     *
     * @throws DomainRuleViolation naming the parameter that is missing or refused
     */
    public static function fromArray(array $input, bool $httpAllowed): self
    {
        $value = static fn (string $name): string => \is_string($input[$name] ?? null) && mb_check_encoding($input[$name], 'UTF-8') ? $input[$name] : '';

        $name = trim((string) preg_replace('/\s+/u', ' ', $value('application_name')));
        if ('' === $name || mb_strlen($name) > 100 || 1 === preg_match('/\p{C}/u', $name)) {
            throw new DomainRuleViolation('application_name is required: the name of the application, 1 to 100 characters.');
        }
        $redirect = self::url($value('redirect_uri'), 'redirect_uri', $httpAllowed);
        $webhook = self::url($value('webhook_url'), 'webhook_url', $httpAllowed);
        $state = $value('state');
        if (1 !== preg_match('/^[\x21-\x7E]{1,512}$/', $state)) {
            throw new DomainRuleViolation('state is required: 1 to 512 printable ASCII characters without spaces, returned unchanged with the answer.');
        }
        if ('S256' !== $value('code_challenge_method')) {
            throw new DomainRuleViolation('code_challenge_method must be S256.');
        }
        $challenge = $value('code_challenge');
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            throw new DomainRuleViolation('code_challenge is required: the base64url SHA-256 of the code verifier (43 characters, no padding).');
        }
        $request = new self($name, $redirect, $webhook, $state, $challenge);
        if (\strlen($request->path()) > self::MAX_PATH_LENGTH) {
            throw new DomainRuleViolation('The connect request is too long.');
        }

        return $request;
    }

    /** The address of the connect page for this request, in one canonical form (a safe return path of a sign-in link). */
    public function path(): string
    {
        return self::PATH.'?'.http_build_query($this->fields(), '', '&', \PHP_QUERY_RFC3986);
    }

    /** @return array<string, string> the request as the parameters the page was opened with */
    public function fields(): array
    {
        return ['application_name' => $this->applicationName, 'redirect_uri' => $this->redirectUri, 'webhook_url' => $this->webhookUrl,
            'state' => $this->state, 'code_challenge' => $this->codeChallenge, 'code_challenge_method' => 'S256'];
    }

    /**
     * The redirect URI with the answer added to its query.
     *
     * @param array<string, string> $answer code and state, or error and state
     */
    public function callback(array $answer): string
    {
        return $this->redirectUri.(str_contains($this->redirectUri, '?') ? '&' : '?').http_build_query($answer, '', '&', \PHP_QUERY_RFC3986);
    }

    /** scheme://host[:port] of the redirect URI (the page's form-action source and what the person is shown). */
    public function redirectOrigin(): string
    {
        return self::origin($this->redirectUri);
    }

    public function webhookOrigin(): string
    {
        return self::origin($this->webhookUrl);
    }

    public static function isConnectPath(?string $path): bool
    {
        return null !== $path && (self::PATH === $path || str_starts_with($path, self::PATH.'?'));
    }

    /**
     * The return path a sign-in or sign-up form may carry (`return_to`): back to the connect
     * page and nowhere else. Whatever form the address arrived in (a URL generator decodes parts
     * of an address inside an address), the result is the canonical one of path(): only the
     * connect parameters, each encoded again. The connect page itself validates their values.
     */
    public static function returnPath(?string $candidate): ?string
    {
        if (!self::isConnectPath($candidate) || \strlen((string) $candidate) > 4 * self::MAX_PATH_LENGTH) {
            return null;
        }
        parse_str((string) substr((string) $candidate, \strlen(self::PATH) + 1), $query);
        $fields = [];
        foreach (self::PARAMETERS as $name) {
            if (\is_string($query[$name] ?? null)) {
                $fields[$name] = $query[$name];
            }
        }

        return LoginLinkService::safeReturnPath(self::PATH.([] === $fields ? '' : '?'.http_build_query($fields, '', '&', \PHP_QUERY_RFC3986)));
    }

    private static function origin(string $url): string
    {
        $parts = (array) parse_url($url);

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function url(string $url, string $parameter, bool $httpAllowed): string
    {
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $rule = \sprintf('%s is required: an absolute %s URL of at most %d characters, without credentials or a fragment.',
            $parameter, $httpAllowed ? 'http(s)' : 'https', self::MAX_URL_LENGTH);
        if ('' === $url || \strlen($url) > self::MAX_URL_LENGTH || !\is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || str_contains($url, '#') || 1 === preg_match('/[^\x21-\x7E]|["<>\\\\^`{|}]/', $url)
            || 1 !== preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)$/', (string) $parts['host'])) {
            throw new DomainRuleViolation($rule);
        }
        if (!('https' === $scheme || ('http' === $scheme && $httpAllowed))) {
            throw new DomainRuleViolation($rule);
        }

        return $url;
    }
}
