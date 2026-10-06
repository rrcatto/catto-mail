<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Access\AccessControl;
use App\Client\AccountAdministration;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/** Browser-level helpers for the dashboard tests (real firewall, emailed sign-in links, CSRF). */
abstract class DashboardTestCase extends ApiTestCase
{
    /** Production-like error pages (debug off): what a user actually sees for 403/404. */
    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
        $this->browser = static::createClient(['debug' => false]);
    }

    protected function accounts(): AccountAdministration
    {
        return $this->container()->get(AccountAdministration::class);
    }

    protected function acl(): AccessControl
    {
        return $this->container()->get(AccessControl::class);
    }

    /** A user; $operator grants the OPERATOR role (every PLATFORM.* permission). */
    protected function newUser(bool $operator = false, ?Client $client = null, ClientMembershipRole $role = ClientMembershipRole::Viewer, ?string $roleKey = null): User
    {
        $user = $this->accounts()->createUser('u'.bin2hex(random_bytes(5)).'@example.test', null, self::actor());
        if ($operator || null !== $roleKey) {
            $this->acl()->grantRoleAsSystem($this->reload($user), $roleKey ?? 'OPERATOR', self::actor());
        }
        if (null !== $client) {
            $this->accounts()->setMembership($this->reload($user), $this->reload($client), $role, self::actor());
        }

        return $user;
    }

    /**
     * Submits the sign-in form for $email and returns the emailed link's path
     * (/dashboard/login/verify?token=…), or null when no email was sent.
     */
    protected function requestLink(string $email, ?string $ip = null, array $server = []): ?string
    {
        $crawler = $this->browser->request('GET', '/dashboard/login', server: $server);
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $before = \count(self::getMailerMessages());
        $this->browser->request('POST', '/dashboard/login', ['email' => $email, '_csrf_token' => $token],
            server: $server + ['REMOTE_ADDR' => $ip ?? '10.77.'.random_int(0, 255).'.'.random_int(1, 254)]);
        self::assertStringEndsWith('/dashboard/login/sent', (string) $this->browser->getResponse()->headers->get('Location'), 'the answer never reveals the outcome');
        $messages = self::getMailerMessages();
        if (\count($messages) === $before) {
            return null;
        }
        $email = end($messages);
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(1, preg_match('#https://\S+/dashboard/login/verify\?token=[A-Za-z0-9_-]+#', (string) $email->getTextBody(), $m), 'the link is in the text body');
        $url = $m[0];
        self::assertStringContainsString($url, (string) $email->getHtmlBody());
        self::assertMatchesRegularExpression('#^https://smarthost\.localhost/dashboard/login/verify\?token=[A-Za-z0-9_-]{43}$#', $url);

        return (string) substr($url, \strlen('https://smarthost.localhost'));
    }

    protected function signIn(User $user): void
    {
        $path = $this->requestLink($user->getEmail());
        self::assertNotNull($path, 'a sign-in link was emailed');
        $this->browser->request('GET', $path);
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/dashboard/login', $location, 'signed in');
    }

    protected function page(string $uri): Response
    {
        $this->browser->request('GET', $uri);

        return $this->browser->getResponse();
    }

    protected function crawler(string $uri): Crawler
    {
        $crawler = $this->browser->request('GET', $uri);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $uri.': '.substr((string) $this->browser->getResponse()->getContent(), 0, 500));

        return $crawler;
    }

    /** Submits the first form whose action is $action, with its own CSRF token and $fields. */
    protected function submit(string $pageUri, string $action, array $fields = []): Response
    {
        $crawler = $this->crawler($pageUri);
        $form = $crawler->filter(\sprintf('form[action="%s"]', $action));
        self::assertGreaterThan(0, $form->count(), "form $action on $pageUri");
        $token = $form->filter('input[name="_token"]')->attr('value');
        $this->browser->request('POST', $action, $fields + ['_token' => $token]);

        return $this->browser->getResponse();
    }

    /** Text of the page with markup removed (for "never says X" assertions). */
    protected static function text(Response $response): string
    {
        return (new Crawler((string) $response->getContent()))->filter('body')->text();
    }
}
