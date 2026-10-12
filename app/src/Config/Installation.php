<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What this installation calls itself (owner decision 2026-10-12): the software can be installed
 * on any domain by anyone, so nothing a person reads names the product where the installation is
 * meant - the top bar, the page titles, the sign-in emails and the acceptable use policy name the
 * installation from its configuration:
 *
 *   APP_SITE_NAME              the installation's name;
 *   APP_ORGANISATION_NAME      who operates it (empty: "the operator of <site name>");
 *   SMARTHOST_PUBLIC_BASE_URL  its domain (the host of the public address);
 *   APP_ADMIN_EMAIL            its contact address (may be empty).
 */
final class Installation
{
    public const DEFAULT_SITE_NAME = 'Catto Mail';

    private readonly string $siteName;
    private readonly string $organisation;
    private readonly string $domain;
    private readonly string $contactEmail;

    public function __construct(
        #[Autowire('%app.site_name%')] string $siteName,
        #[Autowire('%app.organisation_name%')] string $organisationName,
        #[Autowire('%app.public_base_url%')] string $publicBaseUrl,
        #[Autowire('%app.admin_email%')] string $adminEmail,
    ) {
        $this->siteName = self::line($siteName) ?: self::DEFAULT_SITE_NAME;
        $this->organisation = self::line($organisationName);
        $host = parse_url(trim($publicBaseUrl), \PHP_URL_HOST);
        $this->domain = \is_string($host) ? strtolower($host) : '';
        $email = trim($adminEmail);
        $this->contactEmail = str_contains($email, '@') ? $email : '';
    }

    public function siteName(): string
    {
        return $this->siteName;
    }

    /** The configured organisation (APP_ORGANISATION_NAME), '' when none is set. */
    public function organisation(): string
    {
        return $this->organisation;
    }

    /** Who operates the installation, as the acceptable use policy names the party a client agrees with. */
    public function operator(): string
    {
        return '' !== $this->organisation ? $this->organisation : 'the operator of '.$this->siteName;
    }

    /**
     * What a document of the installation (the acceptable use policy) may name: placeholder => value.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        return [
            '{{site_name}}' => $this->siteName,
            '{{domain}}' => '' !== $this->domain ? $this->domain : 'this installation',
            '{{organisation}}' => $this->operator(),
            '{{contact}}' => '' !== $this->contactEmail ? $this->contactEmail : 'the administrator of this installation',
        ];
    }

    /** The host of the installation's public address, '' when none is configured. */
    public function domain(): string
    {
        return $this->domain;
    }

    /** The administrator's address, '' when none is set. */
    public function contactEmail(): string
    {
        return $this->contactEmail;
    }

    /** One line without control characters: these values go into titles and mail headers. */
    private static function line(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }
}
