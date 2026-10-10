<?php

declare(strict_types=1);

namespace App\Command;

use App\Access\AccessControl;
use App\Audit\AuditActor;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Entity\User;
use App\Entity\WebhookEndpoint;
use App\Security\DashboardUserProvider;
use App\Sending\AddressNormalizer;
use App\Util\InstallationTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base for the administrative console commands. Administration that is not part
 * of the public API lives here, never in undocumented /v1 endpoints. Commands run
 * as the least-privilege smarthost_app role and are audited as actor "system".
 */
abstract class AdminCommand extends Command
{
    protected EntityManagerInterface $em;
    protected DashboardUserProvider $userProvider;
    protected AccessControl $accessControl;
    protected InstallationTime $installationTime;

    #[Required]
    public function setAdminDependencies(EntityManagerInterface $em, DashboardUserProvider $userProvider, AccessControl $accessControl, InstallationTime $installationTime): void
    {
        $this->em = $em;
        $this->userProvider = $userProvider;
        $this->accessControl = $accessControl;
        $this->installationTime = $installationTime;
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            return $this->handle($input, $io);
        } catch (DomainRuleViolation|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    abstract protected function handle(InputInterface $input, SymfonyStyle $io): int;

    protected function actor(): AuditActor
    {
        return AuditActor::system('console:'.$this->getName());
    }

    protected function client(string $id): Client
    {
        return $this->find(Client::class, $id) ?? throw new DomainRuleViolation("No client $id.");
    }

    protected function user(string $email): User
    {
        return $this->userProvider->findByEmail($email) ?? throw new DomainRuleViolation("No user with login email $email.");
    }

    /**
     * The operator performing an audited D-30/D-05 action (--operator=<login email>):
     * an enabled user whose roles grant $permission (App\Access\PermissionCatalog).
     * Their user id is the audit actor and, for unmatched-DSN match requests,
     * resolution_requested_by.
     */
    protected function operator(?string $email, string $permission): User
    {
        if (null === $email || '' === trim($email)) {
            throw new DomainRuleViolation('--operator=<operator login email> is required for this action.');
        }
        $user = $this->user(trim($email));
        $this->accessControl->resolve($user);
        if ($user->isDisabled() || !$user->hasPermission($permission)) {
            throw new DomainRuleViolation("$email is not an enabled user with the $permission permission.");
        }

        return $user;
    }

    /** Period options of the usage and billing commands: --period NAME, --month YYYY-MM, or --from/--to dates. */
    protected function addPeriodOptions(): void
    {
        $this->addOption('period', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'current_day, current_month or previous_month')
            ->addOption('month', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'A calendar month, YYYY-MM (in SMARTHOST_TIMEZONE)')
            ->addOption('from', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Custom period start date YYYY-MM-DD (in SMARTHOST_TIMEZONE, inclusive)')
            ->addOption('to', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Custom period end date YYYY-MM-DD (in SMARTHOST_TIMEZONE, exclusive)');
    }

    protected function usagePeriod(InputInterface $input, string $default = 'current_month'): \App\Usage\UsagePeriod
    {
        if (null !== $input->getOption('from') || null !== $input->getOption('to')) {
            return \App\Usage\UsagePeriod::custom((string) $input->getOption('from'), (string) $input->getOption('to'), $this->installationTime->zone);
        }
        if (null !== $input->getOption('month')) {
            return \App\Usage\UsagePeriod::month((string) $input->getOption('month'), $this->installationTime->zone);
        }

        return \App\Usage\UsagePeriod::named((string) ($input->getOption('period') ?? $default), $this->installationTime->zone);
    }

    protected function webhookEndpoint(string $id): WebhookEndpoint
    {
        return $this->find(WebhookEndpoint::class, $id) ?? throw new DomainRuleViolation("No webhook endpoint $id.");
    }

    protected function sendingDomain(Client $client, string $domain): SendingDomain
    {
        $normalized = AddressNormalizer::normalizeDomain(rtrim(trim($domain), '.')) ?? '';

        return $this->em->getRepository(SendingDomain::class)->findOneBy(['client' => $client, 'domain' => $normalized])
            ?? throw new DomainRuleViolation("$domain is not a sending domain of client {$client->getId()}.");
    }

    /** Reads a secret from the first line of STDIN (never from argv, which is visible in process lists). */
    protected function secretFromStdin(InputInterface $input): string
    {
        $stream = $input instanceof \Symfony\Component\Console\Input\StreamableInputInterface && $input->getStream() ? $input->getStream() : \STDIN;
        $line = fgets($stream);

        return false === $line ? '' : rtrim($line, "\r\n");
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, string $id): ?object
    {
        return \Symfony\Component\Uid\Uuid::isValid($id) ? $this->em->find($class, \Symfony\Component\Uid\Uuid::fromString($id)) : null;
    }
}
