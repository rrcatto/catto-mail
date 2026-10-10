<?php

declare(strict_types=1);

namespace App\Command;

use App\System\SettingCatalog;
use App\System\SettingOverrides;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The settings changed in the dashboard (System › Settings), from the console: list them, set
 * one, or reset one (or all) to the infra/.env value. For when the dashboard is unreachable.
 * Changes are audited with the operator (SYSTEM.SETTINGS.MANAGE) and take effect after
 * `smarthostctl prod settings-apply` (development: `smarthostctl settings-apply`).
 */
#[AsCommand('smarthost:settings', 'List, set or reset the settings changed in the dashboard (audited)')]
final class SettingsCommand extends AdminCommand
{
    public function __construct(
        private readonly SettingOverrides $overrides,
        private readonly SettingCatalog $catalog,
        #[Autowire('%env(SMARTHOST_ENV)%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'list | set | reset')
            ->addArgument('name', InputArgument::OPTIONAL, 'The setting (a variable of infra/.env), or --all with reset')
            ->addArgument('value', InputArgument::OPTIONAL, 'The value (set)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'reset: every setting changed in the dashboard')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Login email of an operator with SYSTEM.SETTINGS.MANAGE')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why (kept in the audit log)');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $action = (string) $input->getArgument('action');
        if ('list' === $action) {
            foreach ($this->overrides->all() as $name => $o) {
                $io->writeln(\sprintf('%s=%s  (%s, %s: %s)', $name, $o['value'], $o['updated_by'] ?? 'system', $o['updated_at'], $o['reason']));
            }

            return Command::SUCCESS;
        }
        $name = (string) $input->getArgument('name');
        $changes = match ($action) {
            'set' => [$name => (string) $input->getArgument('value')],
            'reset' => $input->getOption('all') ? array_fill_keys(array_keys($this->overrides->all()), null) : [$name => null],
            default => throw new \InvalidArgumentException('action must be list, set or reset'),
        };
        if ('set' === $action && !$this->catalog->has($name)) {
            throw new \InvalidArgumentException("$name cannot be changed in the dashboard (see docs/contracts/settings.json).");
        }
        $operator = $this->operator($input->getOption('operator'), 'SYSTEM.SETTINGS.MANAGE');
        $changed = $this->overrides->change($changes, (string) $input->getOption('reason'), $operator, $this->environment);
        $apply = 'production' === $this->environment ? 'smarthostctl prod settings-apply' : 'smarthostctl settings-apply';
        $io->writeln([] === $changed ? 'nothing changed' : 'changed: '.implode(', ', $changed)." (apply with $apply)");

        return Command::SUCCESS;
    }
}
