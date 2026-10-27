<?php

declare(strict_types=1);

namespace App\Domain;

use App\Config\Installation;
use App\Entity\SendingDomain;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Tells an account's admins that a sending domain is ready (owner decision 2026-10-27): its
 * signing key is active, so they need not wait on the domain's page while DNS spreads. One
 * email to each admin of the account; a failure is logged and changes nothing.
 */
final class DomainReadyMail
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly Installation $installation,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
        #[Autowire('%app.public_base_url%')] private readonly string $publicBaseUrl,
    ) {
    }

    public function send(SendingDomain $domain): void
    {
        $client = $domain->getClient();
        $admins = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT u.email FROM client_memberships m JOIN users u ON u.id = m.user_id
             WHERE m.client_id = ? AND m.role = 'admin' AND u.status = 'active' ORDER BY u.email
            SQL, [$client->getId()->toRfc4122()]);
        $url = rtrim($this->publicBaseUrl, '/').'/dashboard/c/'.$client->getId()->toRfc4122().'/sending-domains/'.$domain->getId()->toRfc4122();
        foreach ($admins as $email) {
            try {
                $this->mailer->send((new TemplatedEmail())
                    ->from(new Address($this->mailFrom, $this->installation->siteName()))
                    ->to(new Address((string) $email))
                    ->subject($domain->getDomain().' is ready to send')
                    ->textTemplate('email/domain_ready.txt.twig')
                    ->context(['domain' => $domain->getDomain(), 'organisation' => $client->getCompanyName(), 'url' => $url]));
            } catch (\Throwable $e) {
                $this->logger->error('The domain-ready email could not be sent.', ['exception' => $e::class, 'sending_domain_id' => $domain->getId()->toRfc4122()]);
            }
        }
    }
}
