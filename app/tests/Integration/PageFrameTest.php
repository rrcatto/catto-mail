<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ClientStatus;
use App\Tests\Support\DashboardTestCase;
use App\Version;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Every HTML page carries the software version in its head (meta application-name), Bootstrap's
 * stylesheet before the application's, and the same footer: what the application is, the
 * administrator's address (APP_ADMIN_EMAIL) and the time zone (owner decisions, 2026-10-10): the
 * front page, the dashboard pages signed in and out, the error page, the API documentation and the
 * re-permission page - and, since the policy page exists (owner decision 2026-10-11), a link to the
 * acceptable use policy. The front page and the dashboard pages lay their content out in Bootstrap's
 * four-column grid, where every child names its span.
 */
final class PageFrameTest extends DashboardTestCase
{
    private const ADMIN = 'admin@smarthost-dev.test'; // APP_ADMIN_EMAIL of the test pod

    private function frame(string $uri, int $status = 200): void
    {
        $response = $this->page($uri);
        self::assertSame($status, $response->getStatusCode(), $uri);
        $page = new Crawler((string) $response->getContent());
        $meta = $page->filter('head meta[name="application-name"]');
        self::assertSame(1, $meta->count(), "$uri: one application-name");
        self::assertSame('catto-mail v'.Version::VERSION, $meta->attr('content'), $uri);
        $sheets = $page->filter('head link[rel="stylesheet"]')->each(static fn (Crawler $l): string => (string) $l->attr('href'));
        self::assertCount(2, $sheets, $uri);
        self::assertMatchesRegularExpression('#^/assets/vendor/bootstrap/dist/css/bootstrap\.min-[^/]+\.css$#', $sheets[0], "$uri: Bootstrap first");
        self::assertMatchesRegularExpression('#^/assets/styles/app-[^/]+\.css$#', $sheets[1], "$uri: the application's stylesheet last");
        self::assertSame(1, $page->filter('footer')->count(), "$uri: one footer");
        $foot = $page->filter('body > footer.sitefoot');
        self::assertSame(1, $foot->count(), "$uri: the footer follows the main content");
        self::assertStringContainsString('catto-mail v'.Version::VERSION.' · Self-hosted email validation', $foot->text(), $uri);
        self::assertSame('mailto:'.self::ADMIN, $foot->filter('a')->attr('href'), $uri);
        self::assertSame(self::ADMIN, $foot->filter('a')->text(), $uri);
        self::assertStringContainsString('Times are shown in SAST (Africa/Johannesburg).', $foot->text(), $uri);
        self::assertSame('Acceptable use policy', $foot->filter('a[href="/acceptable-use-policy"]')->text(), "$uri: the footer links to the policy");
    }

    public function testEveryKindOfPageHasTheVersionAndTheFooter(): void
    {
        foreach (['/', '/dashboard/login', '/dashboard/login/sent', '/docs/api', '/acceptable-use-policy', '/p/seed-test'] as $uri) {
            $this->frame($uri);
        }
        $this->frame('/p/'.str_repeat('x', 43), 404);   // re-permission page: unknown link
        $this->frame('/no-such-page', 404);             // the error page
        $client = $this->newClient();
        $this->signIn($this->newUser(true));
        foreach (['/dashboard/operator', '/dashboard/operator/help', '/dashboard/c/'.$client->getId()->toRfc4122()] as $uri) {
            $this->frame($uri);
        }
        $this->frame('/dashboard/operator/no-such-page', 404);
    }

    public function testEveryGridChildNamesItsSpan(): void
    {
        $client = $this->newClient(ClientStatus::Active, 'Grid Co');
        $this->verifiedDomain($client);
        $cid = $client->getId()->toRfc4122();
        $this->signIn($this->newUser(roleKey: 'ADMIN'));   // every page, the system and batch pages included
        $pages = ['/', '/dashboard/operator', '/dashboard/operator/system', '/dashboard/operator/suppressions', '/dashboard/operator/unmatched-dsns',
            '/dashboard/operator/webhooks', '/dashboard/operator/clients', "/dashboard/operator/clients/$cid", '/dashboard/operator/alerts',
            '/dashboard/operator/usage', '/dashboard/operator/batches', '/dashboard/operator/setup', '/dashboard/operator/setup/dns',
            '/dashboard/operator/system/diagnostics', '/dashboard/operator/system/settings', '/dashboard/operator/system/requests',
            '/dashboard/operator/audit', '/dashboard/operator/users', '/dashboard/operator/roles', '/dashboard/operator/help',
            '/dashboard/operator/help/dns', '/dashboard/search?q=grid', "/dashboard/c/$cid", "/dashboard/c/$cid/send-jobs",
            "/dashboard/c/$cid/validation-jobs", "/dashboard/c/$cid/suppressions", "/dashboard/c/$cid/sending-domains",
            "/dashboard/c/$cid/api-keys", "/dashboard/c/$cid/webhooks", "/dashboard/c/$cid/connections", "/dashboard/c/$cid/usage", '/acceptable-use-policy'];
        $grids = 0;
        foreach ($pages as $uri) {
            foreach ($this->crawler($uri)->filter('main .grid > *') as $child) {
                if ('input' === $child->nodeName && 'hidden' === $child->getAttribute('type')) {
                    continue;
                }
                ++$grids;
                self::assertMatchesRegularExpression('/(^|\s)g-col-[1-4](\s|$)/', $child->getAttribute('class'),
                    "$uri: <{$child->nodeName} class=\"{$child->getAttribute('class')}\"> in a .grid names no span");
            }
        }
        self::assertGreaterThan(50, $grids, 'the pages use the grid');
    }

    public function testWithoutAnAdministratorAddressTheFooterSaysWhomToAsk(): void
    {
        $this->withEnv(['APP_ADMIN_EMAIL' => ''], function (): void {
            $foot = $this->crawler('/dashboard/login')->filter('footer.sitefoot');
            self::assertSame(0, $foot->filter('a[href^="mailto:"]')->count());
            self::assertStringContainsString('contact the administrator of this installation.', $foot->text());
            self::assertStringContainsString('catto-mail v'.Version::VERSION, $foot->text());
        });
    }
}
