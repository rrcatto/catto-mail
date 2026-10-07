<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Entity\BillingStatement;
use App\Usage\BillingStatementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The provider-neutral billing boundary (Phase 9; PLATFORM.USAGE.EXPORT, audited):
 *   prepare --month=YYYY-MM [--client=ID]     draft statements with totals and reconciliation
 *   finalize <statement-id>                   freeze a consistent draft
 *   mark-exported <statement-id> --reference=EXTERNAL-ID   the billing system took it over
 *   void <statement-id> --note=...            withdraw a draft or finalized statement
 *   list --month=YYYY-MM                      statements of the period
 */
#[AsCommand('smarthost:billing:statement', 'Prepare, finalize, mark exported, void or list billing statements (operator; audited)')]
final class BillingStatementCommand extends AdminCommand
{
    public function __construct(private readonly BillingStatementService $statements)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare | finalize | mark-exported | void | list')
            ->addArgument('statement-id', InputArgument::OPTIONAL)
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'prepare: one client (default: every client with usage in the month)')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'mark-exported: the external billing system\'s reference')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'void: why')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)');
        $this->addPeriodOptions();
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $action = (string) $input->getArgument('action');
        if ('list' === $action) {
            $period = $this->usagePeriod($input, 'previous_month');
            $rows = $this->em->getConnection()->fetchAllAssociative(<<<'SQL'
                SELECT s.id::text AS id, c.company_name, s.status, s.reconciliation_status, s.external_reference,
                       (SELECT string_agg(l.usage_type || '=' || l.quantity, ' ' ORDER BY l.usage_type) FROM billing_statement_lines l WHERE l.statement_id = s.id) AS totals
                  FROM billing_statements s JOIN clients c ON c.id = s.client_id
                 WHERE s.period_start = ? AND s.period_end = ? ORDER BY c.company_name, s.created_at
                SQL, [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')]);
            $io->table(['statement', 'client', 'status', 'reconciliation', 'external reference', 'totals'], array_map('array_values', $rows));

            return Command::SUCCESS;
        }
        $operator = $this->operator($input->getOption('operator'), 'PLATFORM.USAGE.EXPORT');
        $actor = AuditActor::user($operator);
        if ('prepare' === $action) {
            $period = $this->usagePeriod($input, 'previous_month');
            $clients = null !== $input->getOption('client') ? [$this->client((string) $input->getOption('client'))]
                : array_map(fn (string $id) => $this->client($id), $this->em->getConnection()->fetchFirstColumn(
                    'SELECT DISTINCT client_id::text FROM usage_records WHERE occurred_at >= ? AND occurred_at < ? ORDER BY 1',
                    [$period->startSql(), $period->endSql()]));
            foreach ($clients as $client) {
                $s = $this->statements->prepare($client, $period, $actor);
                $io->writeln(\sprintf('%s %s %s reconciliation=%s', $s->getId(), $client->getCompanyName(), $s->getStatus()->value, $s->getReconciliationStatus()->value));
            }

            return Command::SUCCESS;
        }
        $id = (string) $input->getArgument('statement-id');
        $statement = \Symfony\Component\Uid\Uuid::isValid($id) ? $this->em->find(BillingStatement::class, \Symfony\Component\Uid\Uuid::fromString($id)) : null;
        if (null === $statement) {
            throw new \InvalidArgumentException("No billing statement $id.");
        }
        match ($action) {
            'finalize' => $this->statements->finalize($statement, $actor),
            'mark-exported' => $this->statements->markExported($statement, (string) $input->getOption('reference'), $actor),
            'void' => $this->statements->void($statement, (string) $input->getOption('note'), $actor),
            default => throw new \InvalidArgumentException('Unknown action.'),
        };
        $io->writeln('status: '.$statement->getStatus()->value);

        return Command::SUCCESS;
    }
}
