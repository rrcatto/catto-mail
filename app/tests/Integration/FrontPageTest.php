<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DashboardTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The public front page (/, owner decision 2026-10-10): someone who types the installation's
 * address sees what the service is, how to sign in, the API documentation and a word for
 * recipients, instead of a 404. Public and static: no session, no cookie, no script, no form.
 */
final class FrontPageTest extends DashboardTestCase
{
    public function testTheAddressAloneShowsTheFrontPage(): void
    {
        $r = $this->page('/');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame([], $r->headers->getCookies(), 'no cookie');
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $r->headers->get('Content-Security-Policy'));
        $page = new Crawler((string) $r->getContent());
        self::assertSame('Catto Mail', $page->filter('title')->text());
        self::assertSame('Email validation and tracked delivery', $page->filter('main h1')->text());
        self::assertSame(0, $page->filter('script, form, iframe, img')->count(), 'static page');
        $links = $page->filter('main a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertContains('/dashboard/login', $links, 'sign-in');
        self::assertContains('/docs/api', $links, 'the API documentation');
        self::assertContains('mailto:admin@smarthost-dev.test', $links, 'recipients can report unwanted mail');
        $text = $page->filter('main')->text();
        self::assertStringContainsString('There is no public sign-up: accounts are opened by invitation.', $text);
        self::assertStringContainsString('It sets no cookies.', $text);
        self::assertStringNotContainsString('delivered', strtolower($text), 'never claims delivery');
    }

    public function testHeadAndOtherMethods(): void
    {
        $this->browser->request('HEAD', '/');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/');
        self::assertSame(405, $this->browser->getResponse()->getStatusCode());
    }

    public function testWithoutAnAdministratorAddressRecipientsAreToldWhomToAsk(): void
    {
        $this->withEnv(['APP_ADMIN_EMAIL' => ''], function (): void {
            $main = $this->crawler('/')->filter('main');
            self::assertSame(0, $main->filter('a[href^="mailto:"]')->count());
            self::assertStringContainsString('To report unwanted mail, contact the administrator of this installation.', $main->text());
        });
    }
}
