<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Access\AccessControl;
use App\Access\PermissionCatalog;
use App\Access\RoleCatalog;
use App\Audit\AuditActor;
use App\Client\AccountAdministration;
use App\Dashboard\ClientAccess;
use App\Dashboard\ClientReadModel;
use App\Dashboard\Listing;
use App\Dashboard\OperatorReadModel;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Entity\User;
use App\Enum\ClientMembershipRole;
use App\Security\LoginLinkService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Users, roles and the access-control list, in the browser (specification 2.7):
 *
 *   Users  (PLATFORM.USER.VIEW / .MANAGE): create users, enable and disable them,
 *          send them a sign-in link, grant and revoke roles (ADMIN only with
 *          SYSTEM.ROLE.MANAGE), add, change and remove client memberships;
 *   Roles  (SYSTEM.ROLE.VIEW / .MANAGE, i.e. ADMIN): create custom roles, edit
 *          which permissions a role grants, delete unused custom roles.
 *
 * Every change goes through AccountAdministration or AccessControl, which apply
 * the rules and write the audit log; this controller parses forms and checks CSRF.
 */
#[Route('/dashboard/operator')]
final class AccessDashboardController extends AbstractController
{
    public function __construct(
        private readonly AccessControl $acl,
        private readonly AccountAdministration $accounts,
        private readonly ClientAccess $access,
        private readonly OperatorReadModel $read,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/users', name: 'dashboard_operator_users', methods: ['GET'])]
    #[Route('/users/{status}', name: 'dashboard_operator_users_view', methods: ['GET'], requirements: ['status' => 'active|disabled'])]
    #[IsGranted('PLATFORM.USER.VIEW')]
    public function users(Request $request, string $status = ''): Response
    {
        // The views have plain addresses (/users/active, /users/disabled; owner decision 2026-10-27).
        $filters = ['status' => $status] + ClientDashboardController::filters($request, ['q', 'role']);
        $listing = Listing::fromRequest($request, array_keys(OperatorReadModel::USER_SORTS), 'email', 'asc');

        return $this->render('dashboard/operator/users.html.twig', [
            'page' => $this->read->users($filters, $listing), 'filters' => $filters, 'listing' => $listing, 'section' => 'users',
            'roles' => $this->acl->roles(), 'clients' => $this->read->clientChoices(), 'membership_roles' => ClientMembershipRole::cases(),
        ]);
    }

    /** Adding a user is its own page (owner decision 2026-10-27: no form beside the title). */
    #[Route('/users/new', name: 'dashboard_operator_user_new', methods: ['GET'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function newUser(): Response
    {
        return $this->userForm([], null);
    }

    #[Route('/users', name: 'dashboard_operator_user_create', methods: ['POST'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function createUser(Request $request, LoginLinkService $links): Response
    {
        $this->assertCsrf($request);
        $actor = $this->access->user();
        try {
            $user = $this->accounts->createUser($request->request->getString('email'), $request->request->getString('display_name'), AuditActor::user($actor));
            $role = $this->acl->role($request->request->getString('role_id'));
            if (null !== $role) {
                $this->acl->grantRole($user, $role, $actor);
            }
            $client = $this->client($request->request->getString('client_id'));
            $membershipRole = ClientMembershipRole::tryFrom($request->request->getString('membership_role'));
            if (null !== $client && null !== $membershipRole) {
                $this->accounts->setMembership($user, $client, $membershipRole, AuditActor::user($actor));
            }
            $this->addFlash('success', \sprintf('User %s created.', $user->getEmail()));
            if ($request->request->getBoolean('send_link')) {
                $this->sendLink($links, $user, $request);
            }

            return $this->redirectToRoute('dashboard_operator_user', ['id' => $user->getId()->toRfc4122()]);
        } catch (DomainRuleViolation $e) {
            return $this->userForm($request->request->all(), $e->getMessage());
        }
    }

    /** @param array<array-key, mixed> $values */
    private function userForm(array $values, ?string $error): Response
    {
        $text = static fn (string $k, int $max): string => \is_string($values[$k] ?? null) ? mb_substr($values[$k], 0, $max) : '';

        return $this->render('dashboard/operator/user_new.html.twig', ['section' => 'users', 'error' => $error,
            'values' => ['email' => $text('email', 320), 'display_name' => $text('display_name', 200), 'role_id' => $text('role_id', 36),
                'client_id' => $text('client_id', 36), 'membership_role' => $text('membership_role', 20) ?: 'viewer', 'send_link' => [] === $values || '1' === ($values['send_link'] ?? null)],
            'roles' => $this->acl->roles(), 'clients' => $this->read->clientChoices(), 'membership_roles' => ClientMembershipRole::cases()],
            new Response('', null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/users/{id}', name: 'dashboard_operator_user', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('PLATFORM.USER.VIEW')]
    public function user(string $id): Response
    {
        $user = $this->loadUser($id);
        $held = $this->acl->roleKeys($user);

        return $this->render('dashboard/operator/user.html.twig', [
            'u' => $user, 'held' => $held, 'roles' => $this->acl->roles(), 'memberships' => $this->read->memberships($id),
            'clients' => $this->read->clientChoices(), 'membership_roles' => ClientMembershipRole::cases(), 'section' => 'users',
            'is_configured_admin' => $this->acl->isConfiguredAdmin($user->getEmail()),
            'is_self' => $user->getId()->equals($this->access->user()->getId()),
        ]);
    }

    #[Route('/users/{id}/profile', name: 'dashboard_operator_user_profile', methods: ['POST'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function profile(string $id, Request $request): RedirectResponse
    {
        $user = $this->loadUser($id);
        $this->assertCsrf($request);
        $actor = $this->access->user();
        $this->accounts->setDisplayName($user, $request->request->getString('display_name'), AuditActor::user($actor));
        $status = $request->request->getString('status');
        if (\in_array($status, ['active', 'disabled'], true)) {
            if ('disabled' === $status && $user->getId()->equals($actor->getId())) {
                $this->addFlash('error', 'You cannot disable your own account.');
            } else {
                $this->accounts->setDisabled($user, 'disabled' === $status, AuditActor::user($actor));
            }
        }
        $this->addFlash('success', 'Saved.');

        return $this->redirectToRoute('dashboard_operator_user', ['id' => $id]);
    }

    #[Route('/users/{id}/roles', name: 'dashboard_operator_user_role', methods: ['POST'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function role(string $id, Request $request): RedirectResponse
    {
        $user = $this->loadUser($id);
        $this->assertCsrf($request);
        $role = $this->acl->role($request->request->getString('role_id')) ?? throw new NotFoundHttpException('Not found.');
        try {
            $changed = 'revoke' === $request->request->getString('action')
                ? $this->acl->revokeRole($user, $role, $this->access->user())
                : $this->acl->grantRole($user, $role, $this->access->user());
            $this->addFlash($changed ? 'success' : 'info', $changed ? 'Role updated.' : 'Nothing changed.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_user', ['id' => $id]);
    }

    #[Route('/users/{id}/memberships', name: 'dashboard_operator_user_membership', methods: ['POST'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function membership(string $id, Request $request): RedirectResponse
    {
        $user = $this->loadUser($id);
        $this->assertCsrf($request);
        $client = $this->client($request->request->getString('client_id'));
        $role = ClientMembershipRole::tryFrom($request->request->getString('membership_role'));
        $actor = AuditActor::user($this->access->user());
        if (null === $client) {
            $this->addFlash('error', 'Choose a client.');
        } elseif ('remove' === $request->request->getString('action')) {
            $this->addFlash('success', $this->accounts->removeMembership($user, $client, $actor) ? 'Membership removed.' : 'There was no membership.');
        } elseif (null === $role) {
            $this->addFlash('error', 'Choose a client role.');
        } else {
            $this->accounts->setMembership($user, $client, $role, $actor);
            $this->addFlash('success', \sprintf('%s is %s of %s.', $user->getEmail(), $role->value, $client->getCompanyName()));
        }

        return $this->redirectToRoute('dashboard_operator_user', ['id' => $id]);
    }

    #[Route('/users/{id}/login-link', name: 'dashboard_operator_user_login_link', methods: ['POST'])]
    #[IsGranted('PLATFORM.USER.MANAGE')]
    public function loginLink(string $id, Request $request, LoginLinkService $links): RedirectResponse
    {
        $user = $this->loadUser($id);
        $this->assertCsrf($request);
        $this->sendLink($links, $user, $request);

        return $this->redirectToRoute('dashboard_operator_user', ['id' => $id]);
    }

    #[Route('/roles', name: 'dashboard_operator_roles', methods: ['GET'])]
    #[IsGranted('SYSTEM.ROLE.VIEW')]
    public function roles(): Response
    {
        $roles = $this->acl->roles();
        $permissionCounts = [];
        foreach ($roles as $r) {
            $permissionCounts[$r->getId()->toRfc4122()] = \count($this->acl->permissionsOfRole($r));
        }

        return $this->render('dashboard/operator/roles.html.twig', ['roles' => $roles, 'user_counts' => $this->acl->roleUserCounts(),
            'permission_counts' => $permissionCounts, 'permission_total' => \count(PermissionCatalog::keys()), 'section' => 'roles']);
    }

    /** Creating a role is its own page (owner decision 2026-10-27: no form beside the title). */
    #[Route('/roles/new', name: 'dashboard_operator_role_new', methods: ['GET'])]
    #[IsGranted('SYSTEM.ROLE.MANAGE')]
    public function newRole(): Response
    {
        return $this->render('dashboard/operator/role_new.html.twig', ['section' => 'roles', 'error' => null, 'values' => ['role_key' => '', 'name' => '', 'description' => '']]);
    }

    #[Route('/roles', name: 'dashboard_operator_role_create', methods: ['POST'])]
    #[IsGranted('SYSTEM.ROLE.MANAGE')]
    public function createRole(Request $request): Response
    {
        $this->assertCsrf($request);
        try {
            $role = $this->acl->createRole($request->request->getString('role_key'), $request->request->getString('name'),
                $request->request->getString('description'), $this->access->user());
            $this->addFlash('success', \sprintf('Role %s created. Choose its permissions below.', $role->getRoleKey()));

            return $this->redirectToRoute('dashboard_operator_role', ['id' => $role->getId()->toRfc4122()]);
        } catch (DomainRuleViolation $e) {
            return $this->render('dashboard/operator/role_new.html.twig', ['section' => 'roles', 'error' => $e->getMessage(),
                'values' => ['role_key' => mb_substr($request->request->getString('role_key'), 0, 64), 'name' => mb_substr($request->request->getString('name'), 0, 100),
                    'description' => mb_substr($request->request->getString('description'), 0, 300)]], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }
    }

    #[Route('/roles/{id}', name: 'dashboard_operator_role', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('SYSTEM.ROLE.VIEW')]
    public function roleDetail(string $id): Response
    {
        $role = $this->acl->role($id) ?? throw new NotFoundHttpException('Not found.');

        return $this->render('dashboard/operator/role.html.twig', [
            'role' => $role, 'granted' => $this->acl->permissionsOfRole($role), 'is_admin_role' => RoleCatalog::ADMIN === $role->getRoleKey(),
            'groups' => PermissionCatalog::grouped(RoleCatalog::ADMIN === $role->getRoleKey()), 'holders' => $this->acl->roleUserCounts()[$id] ?? 0,
            'section' => 'roles',
        ]);
    }

    #[Route('/roles/{id}', name: 'dashboard_operator_role_update', methods: ['POST'])]
    #[IsGranted('SYSTEM.ROLE.MANAGE')]
    public function updateRole(string $id, Request $request): RedirectResponse
    {
        $role = $this->acl->role($id) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        try {
            $this->acl->updateRole($role, $request->request->getString('name'), $request->request->getString('description'), $this->access->user());
            $keys = $request->request->all('permissions');
            $this->acl->setRolePermissions($role, array_values(array_filter($keys, 'is_string')), $this->access->user());
            $this->addFlash('success', 'Role saved. Users holding it get the new permissions at their next request.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_role', ['id' => $id]);
    }

    #[Route('/roles/{id}/delete', name: 'dashboard_operator_role_delete', methods: ['POST'])]
    #[IsGranted('SYSTEM.ROLE.MANAGE')]
    public function deleteRole(string $id, Request $request): RedirectResponse
    {
        $role = $this->acl->role($id) ?? throw new NotFoundHttpException('Not found.');
        $this->assertCsrf($request);
        try {
            $this->acl->deleteRole($role, $this->access->user());
            $this->addFlash('success', \sprintf('Role %s deleted.', $role->getRoleKey()));

            return $this->redirectToRoute('dashboard_operator_roles');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_role', ['id' => $id]);
        }
    }

    private function sendLink(LoginLinkService $links, User $user, Request $request): void
    {
        try {
            $sent = $links->request($user->getEmail(), $request->getClientIp());
            $this->addFlash($sent ? 'success' : 'error', $sent
                ? \sprintf('A sign-in link was emailed to %s.', $user->getEmail())
                : 'No link was sent (the account is disabled or too many links were requested recently).');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }
    }

    private function loadUser(string $id): User
    {
        $user = ClientReadModel::isUuid($id) ? $this->em->find(User::class, Uuid::fromString($id)) : null;

        return $user ?? throw new NotFoundHttpException('Not found.');
    }

    private function client(string $id): ?Client
    {
        return ClientReadModel::isUuid($id) ? $this->em->find(Client::class, Uuid::fromString($id)) : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
