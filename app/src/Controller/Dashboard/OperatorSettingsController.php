<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Dashboard\ClientAccess;
use App\Domain\DomainRuleViolation;
use App\Enum\SystemRequestAction;
use App\System\AppliedSettings;
use App\System\SettingCatalog;
use App\System\SettingOverrides;
use App\System\SystemRequests;
use App\System\SystemState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * System › Settings: the operational settings of docs/contracts/settings.json with their value
 * from infra/.env, the value set in the dashboard (which takes precedence without changing the
 * file) and the value in force. Viewing needs PLATFORM.SYSTEM.VIEW; changing and applying
 * SYSTEM.SETTINGS.MANAGE. Applying is a host-agent request (settings.apply): this controller
 * never runs a host command.
 */
#[Route('/dashboard/operator/system/settings')]
final class OperatorSettingsController extends AbstractController
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly SettingCatalog $catalog,
        private readonly SettingOverrides $overrides,
        private readonly AppliedSettings $applied,
        private readonly SystemRequests $requests,
        private readonly SystemState $state,
        #[Autowire('%env(SMARTHOST_ENV)%')] private readonly string $environment,
    ) {
    }

    #[Route('', name: 'dashboard_operator_settings', methods: ['GET'])]
    #[IsGranted('PLATFORM.SYSTEM.VIEW')]
    public function index(): Response
    {
        $overrides = $this->overrides->all();
        $state = $this->applied->state();
        $groups = [];
        $pending = 0;
        foreach ($this->catalog->grouped($this->environment) as $group => $entries) {
            $rows = [];
            foreach ($entries as $name => $entry) {
                $override = $overrides[$name] ?? null;
                $inForce = null === $state ? null : ($state['applied'][$name] ?? $state['config'][$name] ?? null);
                $wanted = null === $state ? null : ($override['value'] ?? $state['config'][$name] ?? null);
                $isPending = null !== $state && (($override['value'] ?? null) !== ($state['applied'][$name] ?? null))
                    && !isset($state['ignored'][$name]);
                $pending += $isPending ? 1 : 0;
                $rows[$name] = $entry + [
                    'config' => $state['config'][$name] ?? null,
                    'override' => $override,
                    'in_force' => $inForce,
                    'pending' => $isPending && $wanted !== $inForce,
                    'ignored' => $state['ignored'][$name] ?? null,
                ];
            }
            $groups[] = ['title' => $group, 'slug' => self::slug($group), 'rows' => $rows];
        }

        return $this->render('dashboard/operator/system/settings.html.twig', [
            'section' => 'settings', 'groups' => $groups, 'state' => $state, 'pending' => $pending,
            'environment' => $this->environment, 'agent_age' => $this->state->agentAge(),
            'last_apply' => $this->requests->latest(SystemRequestAction::SettingsApply),
            'can_manage' => $this->isGranted('SYSTEM.SETTINGS.MANAGE'),
        ]);
    }

    /** Save the changed values of one group (an empty field clears the dashboard value). */
    #[Route('', name: 'dashboard_operator_settings_save', methods: ['POST'])]
    #[IsGranted('SYSTEM.SETTINGS.MANAGE')]
    public function save(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $group = $request->request->getString('group');
        $entries = $this->catalog->grouped($this->environment)[$group] ?? [];
        $submitted = $request->request->all('value');
        $changes = [];
        foreach ($entries as $name => $entry) {
            if (\array_key_exists($name, $submitted)) {
                $changes[$name] = \is_string($submitted[$name]) ? $submitted[$name] : null;
            }
        }
        try {
            $changed = $this->overrides->change($changes, $request->request->getString('reason'), $this->access->user(), $this->environment);
            $this->addFlash('success', [] === $changed ? 'Nothing changed.'
                : \sprintf('Saved %d %s. %s', \count($changed), 1 === \count($changed) ? 'setting' : 'settings',
                    'production' === $this->environment ? 'Press “Apply changes” to put them in force.' : 'Run infra/bin/smarthostctl settings-apply to put them in force.'));
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('dashboard_operator_settings').'#'.self::slug($group));
    }

    /** Ask the host agent to render the configuration with the dashboard values and restart what changed. */
    #[Route('/apply', name: 'dashboard_operator_settings_apply', methods: ['POST'])]
    #[IsGranted('SYSTEM.SETTINGS.MANAGE')]
    public function apply(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        try {
            $this->requests->request(SystemRequestAction::SettingsApply, [], $this->access->user(), $request->request->getString('note') ?: null);
            $this->addFlash('success', 'Requested. The host agent renders the configuration and restarts the affected services within a minute or two; the outcome appears here and under System › Host requests.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_settings');
    }

    private static function slug(string $group): string
    {
        return 'g-'.trim(strtolower((string) preg_replace('/[^A-Za-z]+/', '-', $group)), '-');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
