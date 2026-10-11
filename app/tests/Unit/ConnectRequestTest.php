<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Connect\ConnectRequest;
use App\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The connect request an application puts in the address of the connect page (specification
 * 2.12): https only outside development, no credentials or fragment, the PKCE method S256, and
 * one canonical path that a sign-in link may carry as its return path.
 */
final class ConnectRequestTest extends TestCase
{
    private const VALID = ['application_name' => '  Example   Lists ', 'redirect_uri' => 'https://lists.example.org/callback?installation=7',
        'webhook_url' => 'https://lists.example.org/hook', 'state' => 'abc~123', 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256'];

    public function testAValidRequestIsNormalisedAndHasOneCanonicalPath(): void
    {
        $request = ConnectRequest::fromArray(self::VALID, false);
        self::assertSame('Example Lists', $request->applicationName);
        self::assertSame('https://lists.example.org', $request->redirectOrigin());
        self::assertSame('https://lists.example.org', $request->webhookOrigin());
        $path = $request->path();
        self::assertStringStartsWith('/dashboard/connect?application_name=Example%20Lists&redirect_uri=https%3A%2F%2Flists.example.org%2Fcallback%3Finstallation%3D7&', $path);
        self::assertMatchesRegularExpression('#^/dashboard/connect\?[A-Za-z0-9_.~%&=-]+$#', $path, 'only characters a return path may hold');
        self::assertSame($path, ConnectRequest::returnPath($path), 'the sign-in link may carry it');
        parse_str((string) parse_url($path, \PHP_URL_QUERY), $query);
        self::assertSame($request->fields(), $query, 'the page opened with it reads the same request');
        self::assertSame(ConnectRequest::fromArray($query, false)->path(), $path);
    }

    public function testTheAnswerIsAddedToTheRedirectUri(): void
    {
        $request = ConnectRequest::fromArray(self::VALID, false);
        self::assertSame('https://lists.example.org/callback?installation=7&code=c%2Bd&state=abc~123', $request->callback(['code' => 'c+d', 'state' => $request->state]));
        $plain = ConnectRequest::fromArray(['redirect_uri' => 'https://lists.example.org/callback'] + self::VALID, false);
        self::assertSame('https://lists.example.org/callback?error=access_denied&state=abc~123', $plain->callback(['error' => 'access_denied', 'state' => $plain->state]));
    }

    public function testPlainHttpIsForDevelopmentOnly(): void
    {
        $http = ['redirect_uri' => 'http://localhost:8180/cattomail/callback', 'webhook_url' => 'http://ctnlist-web/cattomail/webhook'] + self::VALID;
        $request = ConnectRequest::fromArray($http, true);
        self::assertSame('http://localhost:8180', $request->redirectOrigin());
        foreach (['redirect_uri', 'webhook_url'] as $parameter) {
            try {
                ConnectRequest::fromArray([$parameter => $http[$parameter]] + self::VALID, false);
                self::fail("http $parameter accepted outside development");
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString("$parameter is required: an absolute https URL", $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): iterable
    {
        yield 'no name' => [['application_name' => ' '], 'application_name'];
        yield 'long name' => [['application_name' => str_repeat('n', 101)], 'application_name'];
        yield 'control character in the name' => [['application_name' => "Lists\u{202E}evil"], 'application_name'];
        yield 'name is not text' => [['application_name' => ['x']], 'application_name'];
        yield 'relative redirect' => [['redirect_uri' => '/callback'], 'redirect_uri'];
        yield 'credentials' => [['redirect_uri' => 'https://user:pass@lists.example.org/'], 'redirect_uri'];
        yield 'fragment' => [['redirect_uri' => 'https://lists.example.org/#code'], 'redirect_uri'];
        yield 'other scheme' => [['redirect_uri' => 'javascript:alert(1)'], 'redirect_uri'];
        yield 'header injection' => [['redirect_uri' => "https://lists.example.org/\r\nSet-Cookie: x=1"], 'redirect_uri'];
        yield 'quote' => [['redirect_uri' => 'https://lists.example.org/"onmouseover="x'], 'redirect_uri'];
        yield 'unicode host' => [['redirect_uri' => 'https://lísts.example.org/'], 'redirect_uri'];
        yield 'too long' => [['webhook_url' => 'https://lists.example.org/'.str_repeat('a', 2000)], 'webhook_url'];
        yield 'webhook fragment' => [['webhook_url' => 'https://lists.example.org/hook#x'], 'webhook_url'];
        yield 'no state' => [['state' => ''], 'state'];
        yield 'state with a space' => [['state' => 'a b'], 'state'];
        yield 'long state' => [['state' => str_repeat('s', 513)], 'state'];
        yield 'plain method' => [['code_challenge_method' => 'plain'], 'code_challenge_method'];
        yield 'no method' => [['code_challenge_method' => null], 'code_challenge_method'];
        yield 'short challenge' => [['code_challenge' => 'abc'], 'code_challenge'];
        yield 'padded challenge' => [['code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-c='], 'code_challenge'];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('refused')]
    public function testARefusedRequestNamesItsParameter(array $override, string $parameter): void
    {
        try {
            ConnectRequest::fromArray($override + self::VALID, true);
            self::fail('accepted');
        } catch (DomainRuleViolation $e) {
            self::assertStringStartsWith($parameter, $e->getMessage());
        }
    }

    public function testOnlyAConnectPathIsAReturnPathAndItIsMadeCanonical(): void
    {
        self::assertSame('/dashboard/connect?state=a', ConnectRequest::returnPath('/dashboard/connect?state=a'));
        foreach ([null, '', '/dashboard', '/dashboard/operator', '/dashboard/connections', '/dashboard/connect/x', '/dashboard/connect/../operator',
            'https://evil.example/dashboard/connect?state=a', '//evil.example/dashboard/connect?state=a', '/dashboard/connect?state='.str_repeat('a', 20000)] as $path) {
            self::assertNull(ConnectRequest::returnPath($path), var_export($path, true));
        }
        // Equivalent forms of the same address become the one of path(): a URL generator decodes
        // the slashes and colons of an address inside an address, and a form encodes spaces as +.
        $request = ConnectRequest::fromArray(self::VALID, false);
        $decoded = '/dashboard/connect?application_name=Example+Lists&redirect_uri=https://lists.example.org/callback%3Finstallation%3D7'
            .'&webhook_url=https://lists.example.org/hook&state=abc~123&code_challenge='.self::VALID['code_challenge'].'&code_challenge_method=S256';
        self::assertSame($request->path(), ConnectRequest::returnPath($decoded));
        // Anything but the connect parameters is dropped, and nothing unsafe survives.
        self::assertSame('/dashboard/connect', ConnectRequest::returnPath('/dashboard/connect?next=//evil.example&x[]=1'));
        self::assertSame('/dashboard/connect?state=a%0D%0ALocation%3A%20https%3A%2F%2Fevil.example', ConnectRequest::returnPath("/dashboard/connect?state=a\r\nLocation: https://evil.example"));
        self::assertSame('/dashboard/connect', ConnectRequest::returnPath("/dashboard/connect?state[]=a\n"));
    }
}
