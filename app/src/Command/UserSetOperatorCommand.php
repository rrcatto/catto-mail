<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\AccountAdministration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('smarthost:user:set-operator', 'Grant or remove the global operator role')]
final class UserSetOperatorCommand extends AdminCommand
{
    public function __construct(private readonly AccountAdministration $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addArgument('operator', InputArgument::REQUIRED, 'yes or no');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $flag = (string) $input->getArgument('operator');
        if (!\in_array($flag, ['yes', 'no'], true)) {
            throw new \InvalidArgumentException('operator must be yes or no.');
        }
        $this->accounts->setOperator($this->user((string) $input->getArgument('email')), 'yes' === $flag, $this->actor());
        $io->writeln('operator: '.$flag);

        return Command::SUCCESS;
    }
}
