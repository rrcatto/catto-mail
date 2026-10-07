<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\LoginLinkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Prints a single-use administrator sign-in link (specification 2.11): the first sign-in
 * after installation, before mail and DNS work, or recovery when sign-in mail cannot be
 * delivered. Run on the host as the service user: `smarthostctl prod admin-link`.
 * ADMIN only, at most 15 minutes, used once, audited; it opens System setup.
 */
#[AsCommand('smarthost:admin:login-link', 'Print a single-use administrator sign-in link (host only; 15 minutes)')]
final class AdminLoginLinkCommand extends AdminCommand
{
    public function __construct(
        private readonly LoginLinkService $links,
        #[Autowire('%app.admin_email%')] private readonly string $adminEmail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Administrator login email (default: APP_ADMIN_EMAIL)')
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Validity, 1 to 15 minutes', '15');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $email = (string) ($input->getOption('email') ?? $this->adminEmail);
        if ('' === trim($email) || 'app.no_admin_email' === $email) {
            throw new \InvalidArgumentException('APP_ADMIN_EMAIL is not set; pass --email.');
        }
        $minutes = max(1, min(15, (int) $input->getOption('minutes')));
        $url = $this->links->issueHostLink($email, 60 * $minutes);
        $io->writeln("sign_in_url: $url");
        $io->writeln("valid_for: $minutes minutes, one use, for $email");

        return Command::SUCCESS;
    }
}
