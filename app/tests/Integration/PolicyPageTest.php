<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DashboardTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The acceptable use policy (owner decision 2026-10-11): /acceptable-use-policy is built from
 * docs/AUP.md (copied into the image), public and static, and every page's footer links to it.
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
        self::assertSame(1, preg_match('/^#\s+(.+)$/m', $source, $title));
        self::assertSame(trim($title[1]), $page->filter('article.policy h1')->text());
        self::assertStringContainsString(trim($title[1]), $page->filter('title')->text());
        preg_match_all('/^##\s+(.+)$/m', $source, $sections);
        self::assertGreaterThanOrEqual(3, \count($sections[1]));
        self::assertSame(array_map('trim', $sections[1]), $page->filter('article.policy h2')->each(static fn (Crawler $h): string => $h->text()));
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
