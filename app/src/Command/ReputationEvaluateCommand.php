<?php

declare(strict_types=1);

namespace App\Command;

use App\Reputation\AlertAdministration;
use App\Reputation\ReputationEvaluator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reputation monitoring (Phase 9):
 *   evaluate                  recompute the per-client metrics and open/update/resolve alerts
 *                             (the production timer runs this every 15 minutes)
 *   acknowledge <alert-id>    record the operator's finding (--operator with
 *                             PLATFORM.ABUSE.MANAGE, --note; audited)
 */
#[AsCommand('smarthost:reputation', 'Evaluate client reputation metrics and alerts, or acknowledge an alert')]
final class ReputationEvaluateCommand extends AdminCommand
{
    public function __construct(
        private readonly ReputationEvaluator $evaluator,
        private readonly AlertAdministration $alerts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'evaluate | acknowledge')
            ->addArgument('alert-id', InputArgument::OPTIONAL)
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'acknowledge: login email of the operator')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'acknowledge: what you found or did')
            ->addOption('json', null, InputOption::VALUE_NONE);
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        if ('acknowledge' === $input->getArgument('action')) {
            $operator = $this->operator($input->getOption('operator'), 'PLATFORM.ABUSE.MANAGE');
            $this->alerts->acknowledge((string) $input->getArgument('alert-id'), $operator, (string) $input->getOption('note'));
            $io->writeln('acknowledged: true');

            return Command::SUCCESS;
        }
        if ('evaluate' !== $input->getArgument('action')) {
            throw new \InvalidArgumentException('Unknown action (evaluate, acknowledge).');
        }
        $result = $this->evaluator->evaluate();
        $io->writeln($input->getOption('json') ? (string) json_encode($result)
            : \sprintf('evaluated %d clients: %d alerts opened, %d updated, %d resolved%s', $result['evaluated_clients'],
                $result['opened'], $result['updated'], $result['resolved'], $result['skipped'] ? ' (skipped: another evaluation is running)' : ''));

        return Command::SUCCESS;
    }
}
