<?php

declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditActor;
use App\Client\ClientLifecycle;
use App\Enum\PolicyAcceptanceSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Client account administration (Phase 9; operator with PLATFORM.CLIENT.MANAGE, audited):
 *   note <text>                       add a private operator note (never shown to the client)
 *   policy-accept <version> --reference=...   record an acceptance made elsewhere
 *   policy-require | policy-exempt --note=...  whether approval needs a policy acceptance
 */
#[AsCommand('smarthost:client:account', 'Client account administration: notes and policy acceptance (operator; audited)')]
final class ClientNoteCommand extends AdminCommand
{
    public function __construct(private readonly ClientLifecycle $lifecycle)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED)
            ->addArgument('action', InputArgument::REQUIRED, 'note | policy-accept | policy-require | policy-exempt')
            ->addArgument('value', InputArgument::OPTIONAL, 'The note text, or the accepted policy version')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Reference of an acceptance made elsewhere (policy-accept)')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of the operator (audit actor)')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why (policy-require / policy-exempt)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $client = $this->client((string) $input->getArgument('client-id'));
        $operator = $this->operator($input->getOption('operator'), 'PLATFORM.CLIENT.MANAGE');
        $actor = AuditActor::user($operator);
        $value = (string) $input->getArgument('value');
        switch ($input->getArgument('action')) {
            case 'note':
                $io->writeln('note_id: '.$this->lifecycle->addNote($client, $value, $actor, $operator)->getId()->toRfc4122());
                break;
            case 'policy-accept':
                $a = $this->lifecycle->recordPolicyAcceptance($client, $value, PolicyAcceptanceSource::OperatorRecorded, $actor, null, $input->getOption('reference'));
                $io->writeln('policy_version: '.$a->getPolicyVersion());
                break;
            case 'policy-require':
            case 'policy-exempt':
                $changed = $this->lifecycle->setPolicyAcceptanceRequired($client, 'policy-require' === $input->getArgument('action'), $actor, (string) $input->getOption('note'));
                $io->writeln('changed: '.($changed ? 'true' : 'false'));
                break;
            default:
                throw new \InvalidArgumentException('Unknown action (note, policy-accept, policy-require, policy-exempt).');
        }

        return Command::SUCCESS;
    }
}
