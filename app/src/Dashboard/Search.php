<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Client;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The dashboard's global search (the top bar). One box finds clients, send and validation
 * jobs, messages, users, suppressions and sending domains by what an operator or a client
 * user has at hand: an identifier (UUID), an email address, a domain, an external reference
 * or a name. Operators search the installation, within their permission keys; in a client
 * workspace every query carries that client's id (the tenant boundary). Lookups are exact
 * or prefix matches on indexed columns, each limited, so a search stays cheap.
 */
final class Search
{
    private const LIMIT = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<array{key: string, title: string, rows: list<array{title: string, meta: string, href: ?string, status: ?array{0: string, 1: string}}>}>
     */
    public function operator(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $can = fn (string $p): bool => $this->security->isGranted($p);
        $clients = $can('PLATFORM.CLIENT.VIEW') || $can('PLATFORM.CLIENT.MANAGE');
        $sections = [];

        if (ClientReadModel::isUuid($q)) {
            $id = strtolower($q);
            if ($clients) {
                $sections['clients'] = $this->clientRows('SELECT id::text AS id, company_name, status, contact_email FROM clients WHERE id = ?', [$id]);
                $sections['send_jobs'] = $this->sendJobRows('sj.id = ?', [$id]);
                $sections['validation_jobs'] = $this->validationJobRows('vj.id = ?', [$id]);
                $sections['messages'] = $this->messageRows('m.id = ?', [$id]);
            }
            if ($can('PLATFORM.USER.VIEW')) {
                $sections['users'] = $this->userRows('id = ?', [$id]);
            }
        } elseif (str_contains($q, '@')) {
            $address = ClientReadModel::normalizedFilter($q) ?? $q;
            if ($clients) {
                $sections['messages'] = $this->messageRows('m.recipient_address = ?', [$address]);
                $sections['clients'] = $this->clientRows(
                    'SELECT id::text AS id, company_name, status, contact_email FROM clients WHERE lower(contact_email) = lower(?) OR lower(billing_contact_email) = lower(?) OR lower(abuse_contact_email) = lower(?) ORDER BY company_name LIMIT '.self::LIMIT,
                    [$q, $q, $q]);
            }
            if ($can('PLATFORM.SUPPRESSION.VIEW')) {
                $sections['suppressions'] = $this->suppressionRows($address, null);
            }
            if ($can('PLATFORM.USER.VIEW')) {
                $sections['users'] = $this->userRows('lower(email) = lower(?)', [$q]);
            }
        } else {
            $like = self::likePrefix($q);
            if ($clients) {
                $sections['clients'] = $this->clientRows(
                    'SELECT id::text AS id, company_name, status, contact_email FROM clients WHERE company_name ILIKE ? ORDER BY company_name LIMIT '.self::LIMIT,
                    ['%'.self::likeEscape($q).'%']);
                $sections['send_jobs'] = $this->sendJobRows('sj.external_reference ILIKE ?', [$like]);
                $sections['validation_jobs'] = $this->validationJobRows('vj.external_reference ILIKE ?', [$like]);
                if (str_contains($q, '.')) {
                    $sections['domains'] = $this->domainRows(strtolower($q));
                }
            }
            if ($can('PLATFORM.SUPPRESSION.VIEW') && str_contains($q, '.')) {
                $sections['suppressions'] = $this->suppressionRows(strtolower($q), null);
            }
            if ($can('PLATFORM.USER.VIEW')) {
                $sections['users'] = $this->userRows('email ILIKE ? OR display_name ILIKE ?', ['%'.self::likeEscape($q).'%', '%'.self::likeEscape($q).'%']);
            }
        }

        return self::sections($sections);
    }

    /** @return list<array{key: string, title: string, rows: list<array<string, mixed>>}> */
    public function client(Client $client, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $cid = $client->getId()->toRfc4122();
        $sections = [];
        if (ClientReadModel::isUuid($q)) {
            $id = strtolower($q);
            $sections['send_jobs'] = $this->sendJobRows('sj.id = ? AND sj.client_id = ?', [$id, $cid]);
            $sections['validation_jobs'] = $this->validationJobRows('vj.id = ? AND vj.client_id = ?', [$id, $cid]);
            $sections['messages'] = $this->messageRows('m.id = ? AND sj.client_id = ?', [$id, $cid]);
        } elseif (str_contains($q, '@')) {
            $address = ClientReadModel::normalizedFilter($q) ?? $q;
            $sections['messages'] = $this->messageRows('m.recipient_address = ? AND sj.client_id = ?', [$address, $cid]);
            $sections['suppressions'] = $this->suppressionRows($address, $cid);
        } else {
            $like = self::likePrefix($q);
            $sections['send_jobs'] = $this->sendJobRows('sj.client_id = ? AND sj.external_reference ILIKE ?', [$cid, $like]);
            $sections['validation_jobs'] = $this->validationJobRows('vj.client_id = ? AND vj.external_reference ILIKE ?', [$cid, $like]);
            if (str_contains($q, '.')) {
                $sections['suppressions'] = $this->suppressionRows(strtolower($q), $cid);
            }
        }

        return self::sections($sections);
    }

    private const TITLES = ['clients' => 'Clients', 'send_jobs' => 'Send jobs', 'validation_jobs' => 'Validation jobs', 'messages' => 'Messages',
        'suppressions' => 'Suppressions', 'domains' => 'Sending domains', 'users' => 'Users'];

    /** @param array<string, list<array<string, mixed>>> $sections */
    private static function sections(array $sections): array
    {
        $out = [];
        foreach (self::TITLES as $key => $title) {
            if (!empty($sections[$key])) {
                $out[] = ['key' => $key, 'title' => $title, 'rows' => $sections[$key]];
            }
        }

        return $out;
    }

    private static function likeEscape(string $q): string
    {
        return addcslashes($q, '%_\\');
    }

    private static function likePrefix(string $q): string
    {
        return self::likeEscape($q).'%';
    }

    /** @param list<mixed> $params */
    private function clientRows(string $sql, array $params): array
    {
        return array_map(fn (array $r): array => ['title' => $r['company_name'], 'meta' => (string) $r['contact_email'],
            'href' => $this->urls->generate('dashboard_operator_client', ['id' => $r['id']]), 'status' => [$r['status'], 'client_status']],
            $this->connection->fetchAllAssociative($sql, $params));
    }

    /** @param list<mixed> $params */
    private function sendJobRows(string $where, array $params): array
    {
        return array_map(fn (array $r): array => ['title' => $r['external_reference'], 'meta' => \sprintf('%s · %s recipients · %s', $r['company_name'], number_format((int) $r['total_recipients']), $r['message_class']),
            'href' => $this->urls->generate('dashboard_client_send_job', ['clientId' => $r['client_id'], 'jobId' => $r['id']]), 'status' => [$r['status'], 'send_job_status'], 'at' => $r['created_at']],
            $this->connection->fetchAllAssociative(<<<SQL
                SELECT sj.id::text AS id, sj.client_id::text AS client_id, c.company_name, sj.external_reference, sj.status, sj.total_recipients, sj.message_class, sj.created_at
                  FROM send_jobs sj JOIN clients c ON c.id = sj.client_id WHERE $where ORDER BY sj.created_at DESC LIMIT 20
                SQL, $params));
    }

    /** @param list<mixed> $params */
    private function validationJobRows(string $where, array $params): array
    {
        return array_map(fn (array $r): array => ['title' => $r['external_reference'] ?? $r['id'], 'meta' => \sprintf('%s · %s addresses', $r['company_name'], number_format((int) $r['total_addresses'])),
            'href' => $this->urls->generate('dashboard_client_validation_job', ['clientId' => $r['client_id'], 'jobId' => $r['id']]), 'status' => [$r['status'], 'validation_job_status'], 'at' => $r['submitted_at']],
            $this->connection->fetchAllAssociative(<<<SQL
                SELECT vj.id::text AS id, vj.client_id::text AS client_id, c.company_name, vj.external_reference, vj.status, vj.total_addresses, vj.submitted_at
                  FROM validation_jobs vj JOIN clients c ON c.id = vj.client_id WHERE $where ORDER BY vj.submitted_at DESC LIMIT 20
                SQL, $params));
    }

    /** @param list<mixed> $params */
    private function messageRows(string $where, array $params): array
    {
        return array_map(fn (array $r): array => ['title' => $r['recipient_address'], 'meta' => \sprintf('%s · job %s', $r['company_name'], $r['external_reference']),
            'href' => $this->urls->generate('dashboard_client_message', ['clientId' => $r['client_id'], 'messageId' => $r['id']]), 'status' => [$r['current_status'], 'message_status'], 'at' => $r['created_at']],
            $this->connection->fetchAllAssociative(<<<SQL
                SELECT m.id::text AS id, sj.client_id::text AS client_id, c.company_name, sj.external_reference, m.recipient_address, m.current_status, m.created_at
                  FROM messages m JOIN send_jobs sj ON sj.id = m.send_job_id JOIN clients c ON c.id = sj.client_id
                 WHERE $where ORDER BY m.created_at DESC LIMIT 20
                SQL, $params));
    }

    /**
     * Active suppressions of an address or domain. In a client workspace only the client's own
     * and the global ones it reported (as on its Suppressions page), never another client's.
     */
    private function suppressionRows(string $value, ?string $clientId): array
    {
        $scope = null === $clientId ? '' : ' AND (s.client_id = ? OR (s.client_id IS NULL AND s.source_client_id = ?))';
        $params = null === $clientId ? [$value] : [$value, $clientId, $clientId];
        $href = null === $clientId
            ? $this->urls->generate('dashboard_operator_suppressions', ['address' => $value, 'state' => ''])
            : $this->urls->generate('dashboard_client_suppressions', ['clientId' => $clientId, 'address' => $value]);

        return array_map(static fn (array $r): array => ['title' => $r['address_or_domain'],
            'meta' => (null === $r['client_id'] ? 'Global' : 'This client only').' · '.Labels::label((string) $r['reason'], 'suppression_reason'),
            'href' => $href, 'status' => null, 'at' => $r['created_at']],
            $this->connection->fetchAllAssociative(<<<SQL
                SELECT s.address_or_domain, s.client_id, s.reason, s.created_at FROM suppressions s
                 WHERE s.address_or_domain = ? AND s.lifted_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > now())$scope
                 ORDER BY s.created_at DESC LIMIT 20
                SQL, $params));
    }

    private function domainRows(string $domain): array
    {
        return array_map(fn (array $r): array => ['title' => $r['domain'], 'meta' => $r['company_name'].' · DKIM '.Labels::label((string) $r['dkim_status'], 'dkim_status'),
            'href' => $this->urls->generate('dashboard_client_domains', ['clientId' => $r['client_id']]), 'status' => [$r['status'], 'domain_status']],
            $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT d.domain, d.status, d.dkim_status, d.client_id::text AS client_id, c.company_name
                  FROM sending_domains d JOIN clients c ON c.id = d.client_id WHERE d.domain = ? ORDER BY c.company_name LIMIT 20
                SQL, [$domain]));
    }

    /** @param list<mixed> $params */
    private function userRows(string $where, array $params): array
    {
        return array_map(fn (array $r): array => ['title' => $r['email'], 'meta' => (string) ($r['display_name'] ?? ''),
            'href' => $this->urls->generate('dashboard_operator_user', ['id' => $r['id']]), 'status' => [$r['status'], 'user_status']],
            $this->connection->fetchAllAssociative("SELECT id::text AS id, email, display_name, status FROM users WHERE $where ORDER BY email LIMIT 20", $params));
    }
}
