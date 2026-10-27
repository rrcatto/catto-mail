<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\DkimSetup;
use App\Enum\SystemCheckSource;
use App\System\ApplicationDiagnostics;
use App\System\SystemChecks;
use App\System\SystemRequests;
use App\System\SystemState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The host agent's interface to the application (specification 2.11). The agent runs on
 * the host as the service user (`smarthostctl prod agent`) and calls these through
 * `podman exec` into the application container; JSON travels on stdin and stdout.
 *
 *   claim [--only=<action>...]  claim pending requests (only those actions, when named); prints them as JSON
 *   finish <id> --failed?       record a request's outcome; stdin {"summary": "...", "result": {...}}
 *   report [--requested]        record checks and state; stdin {"checks": [...], "state": {"host_report": {...}, ...},
 *                               "complete": true when the checks are the full set (agent checks not in it are retired)}
 *   diagnostics [--requested]   run the application's own checks (prints them as JSON)
 *   domains                     look again at the sending domains waiting for their DKIM record and ask for
 *                               the activation of those now published (App\Domain\DkimSetup::advance; JSON)
 *
 * Finishing a DKIM request of the client dashboard also records its outcome for the sending domain,
 * in the same transaction (DkimSetup::requestFinished).
 * Nothing here runs a host command; secrets are stripped before anything is stored.
 */
#[AsCommand('smarthost:system:agent', 'Host-agent protocol: claim and finish requests, record checks and host reports, advance DKIM set-ups')]
final class SystemAgentCommand extends AdminCommand
{
    public function __construct(
        private readonly SystemRequests $requests,
        private readonly SystemChecks $checks,
        private readonly SystemState $state,
        private readonly ApplicationDiagnostics $diagnostics,
        private readonly DkimSetup $dkim,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'claim | finish | report | diagnostics | domains')
            ->addArgument('request-id', InputArgument::OPTIONAL)
            ->addOption('failed', null, InputOption::VALUE_NONE, 'finish: the request failed')
            ->addOption('requested', null, InputOption::VALUE_NONE, 'report/diagnostics: an administrator asked for this run (kept in the history)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'claim: only requests of this action (dkim.generate, dkim.activate, ...)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $requested = (bool) $input->getOption('requested');
        switch ((string) $input->getArgument('action')) {
            case 'claim':
                $this->requests->expireStale();
                $only = array_values(array_filter((array) $input->getOption('only'), static fn (mixed $a): bool => \is_string($a)
                    && null !== \App\Enum\SystemRequestAction::tryFrom($a)));
                $io->writeln(json_encode($this->requests->claim(10, $only), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            case 'finish':
                $in = $this->stdin($input);
                $succeeded = !$input->getOption('failed');
                $result = \is_array($in['result'] ?? null) ? $in['result'] : [];
                $this->em->getConnection()->transactional(function () use ($input, $in, $succeeded, $result): void {
                    $finished = $this->requests->finish((string) $input->getArgument('request-id'), $succeeded, (string) ($in['summary'] ?? ''), $result);
                    $this->dkim->requestFinished($finished['action'], $finished['params'], $succeeded, $result);
                });
                $io->writeln('recorded');

                return Command::SUCCESS;
            case 'domains':
                $io->writeln(json_encode($this->dkim->advance(), \JSON_THROW_ON_ERROR));

                return Command::SUCCESS;
            case 'report':
                $in = $this->stdin($input);
                $checks = \is_array($in['checks'] ?? null) ? array_values($in['checks']) : [];
                $n = $this->checks->record($checks, SystemCheckSource::Agent, $requested);
                $retired = true === ($in['complete'] ?? false) && $n > 0
                    ? $this->checks->retireAgentChecksExcept(array_map(static fn ($c): string => (string) (\is_array($c) ? ($c['key'] ?? '') : ''), $checks))
                    : 0;
                foreach (\is_array($in['state'] ?? null) ? $in['state'] : [] as $key => $value) {
                    if (\is_string($key) && \is_array($value)) {
                        $this->state->put($key, $value);
                    }
                }
                $this->state->put(SystemState::AGENT, ['at' => date('c')]);
                $io->writeln("recorded $n checks".($retired > 0 ? ", retired $retired no longer reported" : ''));

                return Command::SUCCESS;
            case 'diagnostics':
                $io->writeln(json_encode($this->diagnostics->run($requested), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            default:
                throw new \InvalidArgumentException('Unknown action (claim, finish, report, diagnostics, domains).');
        }
    }

    /** @return array<string, mixed> */
    private function stdin(InputInterface $input): array
    {
        $stream = $input instanceof \Symfony\Component\Console\Input\StreamableInputInterface && null !== $input->getStream() ? $input->getStream() : \STDIN;
        $raw = stream_get_contents($stream, 4 * 1024 * 1024);
        $data = '' === trim((string) $raw) ? [] : json_decode((string) $raw, true);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Expected a JSON object on stdin.');
        }

        return $data;
    }
}
