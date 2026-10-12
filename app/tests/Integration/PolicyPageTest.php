<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Installation;
use App\Tests\Support\DashboardTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The acceptable use policy (owner decisions 2026-10-11 and 2026-10-12): /acceptable-use-policy is
 * built from docs/AUP.md (copied into the image), public and static, and every page's footer
 * links to it. The document names no product: the page names the installation it runs on (its
 * name, domain and operator) from the configuration, and shows one address only: abuse@ the
 * domain. The administrator's address is never on it.
 */
final class PolicyPageTest extends DashboardTestCase
{
    public function testThePolicyPageIsBuiltFromTheMarkdownDocument(): void
    {
        $response = $this->page('/acceptable-use-policy');
        self::assertSame(200, $response->getStatusCode());
        $page = new Crawler((string) $response->getContent());
        $source = (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/config/contracts/AUP.md');
        self::assertNotSame('', trim($source), 'the image carries docs/AUP.md');

        // Its structure is the document's: the title, every section heading, lists and the table of rates.
        $names = self::getContainer()->get(Installation::class)->placeholders();
        self::assertSame(1, preg_match('/^#\s+(.+)$/m', $source, $title));
        self::assertSame(strtr(trim($title[1]), $names), $page->filter('article.policy h1')->text());
        self::assertStringContainsString(strtr(trim($title[1]), $names), $page->filter('title')->text());
        preg_match_all('/^##\s+(.+)$/m', $source, $sections);
        self::assertGreaterThanOrEqual(3, \count($sections[1]));
        self::assertSame(array_map('trim', $sections[1]), $page->filter('article.policy h2')->each(static fn (Crawler $h): string => $h->text()));
        self::assertStringNotContainsString('{{', $page->filter('article.policy')->text(), 'every placeholder is filled');
        self::assertGreaterThan(5, $page->filter('article.policy ol > li')->count());
        self::assertGreaterThan(0, $page->filter('article.policy ol > li > ul > li')->count(), 'nested points');
        self::assertSame(['Measure', 'Warning', 'Action likely'], $page->filter('article.policy table thead th')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame(2, $page->filter('article.policy table tbody tr')->count());
        self::assertStringNotContainsString('| ---', $page->filter('article.policy')->text(), 'no Markdown is left over');
        self::assertStringNotContainsString('##', $page->filter('article.policy')->text());

        // Public and static: no session, no cookie, no script, cacheable.
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(0, $page->filter('script')->count());
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->browser->request('POST', '/acceptable-use-policy');
        self::assertSame(405, $this->browser->getResponse()->getStatusCode());
    }

    public function testThePolicyNamesTheInstallationAndNeverTheProduct(): void
    {
        // The shipped document names no product, domain or repository: only placeholders.
        $source = (string) file_get_contents(self::getContainer()->getParameter('kernel.project_dir').'/config/contracts/AUP.md');
        foreach (['catto-mail', 'cattomail', 'catto mail', 'smarthost', 'github'] as $name) {
            self::assertStringNotContainsStringIgnoringCase($name, $source);
        }
        foreach (['{{site_name}}', '{{domain}}', '{{organisation}}'] as $placeholder) {
            self::assertStringContainsString($placeholder, $source);
        }
        self::assertSame(1, preg_match('/^Version ([A-Za-z0-9][A-Za-z0-9._-]*)/m', $source), 'the document states its version');
        self::assertStringContainsString('to abuse@{{domain}}.', $source, 'abuse reports go to the abuse mailbox of the installation\'s domain');
        self::assertStringNotContainsString('{{contact}}', $source, 'the administrator\'s address is not part of the policy');
        self::assertSame(1, preg_match_all('/@/', $source), 'one address in the whole policy: the abuse address');

        $this->withEnv(['APP_SITE_NAME' => 'Example Post', 'APP_ORGANISATION_NAME' => 'Example & Sons (Pty) Ltd',
            'SMARTHOST_PUBLIC_BASE_URL' => 'https://post.example.org', 'APP_ADMIN_EMAIL' => 'front_desk@example.org'], function (): void {
                $response = $this->page('/acceptable-use-policy');
                self::assertSame(200, $response->getStatusCode());
                $page = new Crawler((string) $response->getContent());
                $policy = $page->filter('article.policy')->text();
                self::assertSame('Example Post Acceptable Use Policy', $page->filter('article.policy h1')->text());
                self::assertStringContainsString('through Example Post (post.example.org), a service run by Example & Sons (Pty) Ltd.', $policy);
                self::assertStringContainsString('You may not use Example Post to send:', $policy);
                // Abuse reports and complaints go to the installation's abuse mailbox, not to the administrator.
                self::assertStringContainsString('Report abuse, and send complaints about mail sent through Example Post, to abuse@post.example.org.', $policy);
                // The administrator's address is nowhere on this page: not in the policy, not in the footer.
                self::assertStringNotContainsString('front_desk@example.org', (string) $response->getContent());
                self::assertStringNotContainsString('administrator', $page->filter('body')->text());
                self::assertSame(['mailto' => 0], ['mailto' => $page->filter('a[href^="mailto:"]')->count()]);
                self::assertSame(1, preg_match_all('/[\w.+-]+@[\w.-]+\w/', $page->filter('body')->text(), $found), 'one address on the page');
                self::assertSame('abuse@post.example.org', $found[0][0]);
                // Every other page still names the administrator in its footer.
                self::assertStringContainsString('front_desk@example.org', (string) $this->page('/')->getContent());
                self::assertStringContainsString('Example &amp; Sons (Pty) Ltd', (string) $response->getContent(), 'a configured value is escaped');
                self::assertStringNotContainsString('{{', $policy);
                self::assertStringNotContainsStringIgnoringCase('catto', $policy);
                // The page around it names the installation too.
                self::assertSame('Example Post Acceptable Use Policy · Example Post', $page->filter('title')->text());
                self::assertSame('Example Post', $page->filter('header .brand')->text());
            });

        // Without an organisation or an administrator's address the policy still says who.
        $this->withEnv(['APP_SITE_NAME' => 'Example Post', 'APP_ORGANISATION_NAME' => '', 'APP_ADMIN_EMAIL' => ''], function (): void {
            $policy = (new Crawler((string) $this->page('/acceptable-use-policy')->getContent()))->filter('article.policy')->text();
            self::assertStringContainsString('a service run by the operator of Example Post.', $policy);
            self::assertStringContainsString(', to abuse@'.self::getContainer()->get(Installation::class)->domain().'.', $policy, 'abuse reports have their address whatever else is configured');
        });
    }

    public function testTheInstallationsNameIsOnEveryPage(): void
    {
        $this->withEnv(['APP_SITE_NAME' => 'Example Post'], function (): void {
            foreach (['/' => 'Example Post', '/dashboard/login' => 'Example Post', '/docs/api' => 'Example Post API'] as $uri => $title) {
                $this->browser->request('GET', $uri);
                $page = new Crawler((string) $this->browser->getResponse()->getContent());
                self::assertStringContainsString($title, $page->filter('title')->text(), $uri);
                self::assertSame('Example Post', $page->filter('.brand')->first()->text(), $uri);
                self::assertStringNotContainsString('Catto Mail', $page->filter('body')->text(), $uri);
            }
        });
    }

    public function testEveryFooterLinksToIt(): void
    {
        foreach (['/', '/dashboard/login', '/docs/api', '/acceptable-use-policy', '/no-such-page'] as $uri) {
            $this->browser->request('GET', $uri);
            $link = (new Crawler((string) $this->browser->getResponse()->getContent()))->filter('footer.sitefoot a[href="/acceptable-use-policy"]');
            self::assertSame(1, $link->count(), $uri);
            self::assertSame('Acceptable use policy', $link->text());
        }
    }
}
