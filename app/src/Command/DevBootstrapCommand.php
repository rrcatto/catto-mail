<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\AccountAdministration;
use App\Domain\SendingDomainService;
use App\Entity\Client;
use App\Entity\SendingDomain;
use App\Enum\ClientMembershipRole;
use App\Enum\ClientStatus;
use App\Enum\DkimStatus;
use App\Security\ApiKeyManager;
use App\Suppression\SuppressionAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Development/test data (refused unless SMARTHOST_ENV is development or test):
 *
 *  - the client "Smarthost development client" (active), reused if it exists;
 *  - its sending domain, by default the disposable development DKIM domain
 *    smarthost-dev.test (RFC 2606), marked verified WITHOUT DNS and DKIM active
 *    with the development selector, matching the key smarthostctl dkim-dev-key
 *    installs in OpenDKIM;
 *  - the D-30 global-opt-out capability for this disposable development client
 *    only (never for any other client);
 *  - a new API key, printed once;
 *  - optionally (--operator-email) a dashboard user with the OPERATOR role (signs in
 *    with an emailed link; no password),
 *    printed once, and an admin membership of the client.
 *
 * Nothing is hard-coded or committed: every credential is generated now.
 */
#[AsCommand('smarthost:dev:bootstrap', 'Create a development client, verified dev sending domain and API key (development/test only)')]
final class DevBootstrapCommand extends AdminCommand
{
    private const CLIENT_NAME = 'Smarthost development client';

    public function __construct(
        private readonly AccountAdministration $accounts,
        private readonly SendingDomainService $domains,
        private readonly ApiKeyManager $keys,
        private readonly SuppressionAdministration $suppressions,
        private readonly string $smarthostEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Development sending domain', 'smarthost-dev.test')
            ->addOption('dkim-selector', null, InputOption::VALUE_REQUIRED, 'DKIM selector of the development key', 'phase1')
            ->addOption('operator-email', null, InputOption::VALUE_REQUIRED, 'Also create a dashboard operator with this login email');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        if (!\in_array($this->smarthostEnv, ['development', 'test'], true)) {
            $io->error('smarthost:dev:bootstrap runs only with SMARTHOST_ENV=development or test.');

            return Command::FAILURE;
        }
        $actor = $this->actor();
        $client = $this->em->getRepository(Client::class)->findOneBy(['companyName' => self::CLIENT_NAME])
            ?? $this->accounts->createClient(self::CLIENT_NAME, 'dev-client@smarthost-dev.test', 'development', ClientStatus::Active, $actor);

        $domainName = (string) $input->getOption('domain');
        $domain = $this->em->getRepository(SendingDomain::class)->findOneBy(['client' => $client, 'domain' => strtolower($domainName)])
            ?? $this->domains->register($client, $domainName, $actor);
        if (!$domain->isVerified()) {
            $this->domains->markVerifiedWithoutDns($domain, $actor, $this->smarthostEnv);
        }
        if (DkimStatus::Active !== $domain->getDkimStatus()) {
            $this->domains->setDkim($domain, DkimStatus::Active, (string) $input->getOption('dkim-selector'), $actor);
        }
        $this->suppressions->setGlobalSuppressionCapability($client, true, $actor, 'development bootstrap (disposable development client)');
        [$key, $raw] = $this->keys->create($client, 'development', $actor);

        $io->writeln('client_id: '.$client->getId()->toRfc4122());
        $io->writeln('sending_domain: '.$domain->getDomain().' (verified without DNS, DKIM '.$domain->getDkimStatus()->value.')');
        $io->writeln('can_submit_global_suppressions: true');
        $io->writeln('api_key_id: '.$key->getId()->toRfc4122());
        $io->writeln('api_key: '.$raw);

        if (null !== $email = $input->getOption('operator-email')) {
            $user = $this->accounts->createUser((string) $email, 'Development operator', $actor);
            $this->accessControl->grantRoleAsSystem($user, 'OPERATOR', $actor);
            $this->accounts->setMembership($user, $client, ClientMembershipRole::Admin, $actor);
            $io->writeln('operator_email: '.$user->getEmail());
            $io->writeln('operator_sign_in: request a link at /dashboard/login (it arrives in Mailpit)');
        }
        $io->note('The API key is shown once and cannot be recovered.');

        return Command::SUCCESS;
    }
}
