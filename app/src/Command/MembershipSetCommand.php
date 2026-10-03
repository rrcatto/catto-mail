<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\AccountAdministration;
use App\Enum\ClientMembershipRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:membership:set', 'Add a user to a client or change the membership role')]
final class MembershipSetCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('client-id', InputArgument::REQUIRED)
            ->addArgument('role', InputArgument::REQUIRED, 'admin, member or viewer');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $role = ClientMembershipRole::tryFrom((string) $input->getArgument('role')) ?? throw new \InvalidArgumentException('Unknown membership role.');
        $m = $this->accounts->setMembership($this->user((string) $input->getArgument('email')),
            $this->client((string) $input->getArgument('client-id')), $role, $this->actor());
        $io->writeln('membership_id: '.$m->getId()->toRfc4122().' role: '.$m->getRole()->value);

        return Command::SUCCESS;
    }
}
