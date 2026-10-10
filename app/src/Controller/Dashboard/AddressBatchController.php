<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\AddressBatch\AddressBatchService;
use App\AddressBatch\BatchFileParser;
use App\AddressBatch\BatchReadModel;
use App\AddressBatch\BatchSender;
use App\AddressBatch\EntryStates;
use App\AddressBatch\UploadStore;
use App\Dashboard\ClientAccess;
use App\Domain\DomainRuleViolation;
use App\Entity\Client;
use App\Enum\AddressBatchPurpose;
use App\Enum\BatchReviewDecision;
use App\System\DeliveryControl;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Clients › Address batches (specification 2.11): upload up to 10,000 addresses, preview,
 * import, validate (through the Email Validator), review the results by separate state
 * dimension, download reports, and send in rollout stages (seed, controlled, rollout,
 * full) after the compliance approval. ADMIN only (SYSTEM.ADDRESS_BATCH.MANAGE).
 */
#[Route('/dashboard/operator/batches')]
#[IsGranted('SYSTEM.ADDRESS_BATCH.MANAGE')]
final class AddressBatchController extends AbstractController
{
    private const PAGE = 100;

    public function __construct(
        private readonly ClientAccess $access,
        private readonly BatchReadModel $read,
        private readonly AddressBatchService $batches,
        private readonly UploadStore $uploads,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    #[Route('', name: 'dashboard_operator_batches', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/operator/batches/index.html.twig', [
            'section' => 'batches', 'batches' => $this->read->batches(), 'clients' => $this->clients(), 'max' => BatchFileParser::MAX_ROWS]);
    }

    #[Route('/preview', name: 'dashboard_operator_batch_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->assertCsrf($request);
        $uploadId = $request->request->getString('upload_id');
        try {
            if ('' === $uploadId) {
                $file = $request->files->get('file');
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    throw new DomainRuleViolation('Choose a file (TXT with one address per line, or CSV with a header row; at most 10 MiB).');
                }
                $bytes = (string) file_get_contents($file->getPathname());
                $uploadId = $this->uploads->put($bytes, $file->getClientOriginalName(), $this->access->user()->getId()->toRfc4122());
                $filename = $file->getClientOriginalName();
            } else {
                ['bytes' => $bytes, 'filename' => $filename] = $this->uploads->get($uploadId, $this->access->user()->getId()->toRfc4122());
            }
            $parsed = BatchFileParser::parse($bytes, $filename, $request->request->getString('column') ?: null);
            $form = $this->batchForm($request);
            $client = $this->client($form['client']);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_batches');
        }

        return $this->render('dashboard/operator/batches/preview.html.twig', [
            'section' => 'batches', 'upload_id' => $uploadId, 'filename' => $filename, 'parsed' => $parsed, 'form' => $form,
            'sample' => \array_slice($parsed['rows'], 0, 20),
            'problems' => \array_slice(array_values(array_filter($parsed['rows'], static fn (array $r): bool => 'imported' !== $r['outcome'])), 0, 50),
            'batch_client' => $client,
        ]);
    }

    #[Route('/import', name: 'dashboard_operator_batch_import', methods: ['POST'])]
    public function import(Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $uploadId = $request->request->getString('upload_id');
        $form = $this->batchForm($request);
        try {
            ['bytes' => $bytes, 'filename' => $filename] = $this->uploads->get($uploadId, $this->access->user()->getId()->toRfc4122());
            $parsed = BatchFileParser::parse($bytes, $filename, $request->request->getString('column') ?: null);
            $id = $this->batches->import($this->client($form['client']), $parsed, $filename, $form['name'], $form['description'], $form['source'],
                AddressBatchPurpose::tryFrom($form['purpose']) ?? AddressBatchPurpose::Validation, $form['list_id'], $this->access->user());
            $this->uploads->delete($uploadId);
            $this->addFlash('success', \sprintf('Imported: %d addresses (%d duplicates and %d malformed rows are listed, none dropped). Next: validate the batch.',
                $parsed['counts']['imported'], $parsed['counts']['duplicate'], $parsed['counts']['malformed']));

            return $this->redirectToRoute('dashboard_operator_batch', ['id' => $id]);
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dashboard_operator_batches');
        }
    }

    #[Route('/{id}', name: 'dashboard_operator_batch', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id, DeliveryControl $delivery): Response
    {
        $batch = $this->batch($id);

        return $this->render('dashboard/operator/batches/show.html.twig', [
            'section' => 'batches', 'batch' => $batch, 'summary' => $this->read->summary($id), 'progress' => $this->read->progress($id),
            'sends' => $this->read->sends($id), 'report' => $this->read->sendReport($id), 'delivery' => $delivery->status(),
        ]);
    }

    #[Route('/{id}/progress', name: 'dashboard_operator_batch_progress', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function progress(string $id): Response
    {
        $this->batch($id);

        return $this->json($this->read->progress($id), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/entries', name: 'dashboard_operator_batch_entries', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function entries(string $id, Request $request): Response
    {
        $batch = $this->batch($id);
        $filter = $request->query->getString('filter', 'all');
        if (!isset(BatchReadModel::FILTERS[$filter])) {
            $filter = 'all';
        }
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->read->count($id, $filter);

        return $this->render('dashboard/operator/batches/entries.html.twig', [
            'section' => 'batches', 'batch' => $batch, 'filter' => $filter, 'filters' => array_keys(BatchReadModel::FILTERS),
            'entries' => $this->read->entries($id, $filter, ($page - 1) * self::PAGE, self::PAGE), 'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)), 'total' => $total, 'labels' => self::labels(),
        ]);
    }

    #[Route('/{id}/export.csv', name: 'dashboard_operator_batch_export', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function export(string $id, Request $request): Response
    {
        $batch = $this->batch($id);
        $filter = $request->query->getString('filter', 'all');
        if (!isset(BatchReadModel::FILTERS[$filter])) {
            throw new NotFoundHttpException('Not found.');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $batch['name']).'-'.$filter.'.csv';

        return new Response($this->read->csv($id, $filter), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/entries/{entryId}', name: 'dashboard_operator_batch_entry', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}', 'entryId' => '[0-9a-f-]{36}'])]
    public function entry(string $id, string $entryId): Response
    {
        $batch = $this->batch($id);
        try {
            $detail = $this->read->entry($id, $entryId);
        } catch (DomainRuleViolation) {
            throw new NotFoundHttpException('Not found.');
        }

        return $this->render('dashboard/operator/batches/entry.html.twig', ['section' => 'batches', 'batch' => $batch] + $detail + ['labels' => self::labels()]);
    }

    #[Route('/{id}/validate', name: 'dashboard_operator_batch_validate', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function validate(string $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $batch = $this->batch($id);
        try {
            $n = $this->batches->validate($id, $this->client((string) $batch['client_id']), $this->access->user());
            $this->addFlash('success', "Validation started for $n addresses. The page updates as the validator works.");
        } catch (DomainRuleViolation|\App\Api\ApiProblem $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_batch', ['id' => $id]);
    }

    #[Route('/{id}/review', name: 'dashboard_operator_batch_review', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function review(string $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->batch($id);
        $decision = BatchReviewDecision::tryFrom($request->request->getString('decision'));
        $filter = $request->request->getString('filter');
        $ids = 'all_in_filter' === $request->request->getString('scope') && isset(BatchReadModel::FILTERS[$filter])
            ? $this->read->ids($id, $filter) : array_map('strval', $request->request->all('entries'));
        $n = $this->batches->review($id, $ids, $decision, $this->access->user());
        $this->addFlash('success', \sprintf('%d addresses: %s.', $n, null === $decision ? 'decision cleared' : ($decision->value.'d after review')));

        return $this->redirectToRoute('dashboard_operator_batch_entries', ['id' => $id, 'filter' => $filter ?: 'all']);
    }

    #[Route('/{id}/entries/{entryId}/typo', name: 'dashboard_operator_batch_typo', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}', 'entryId' => '[0-9a-f-]{36}'])]
    public function typo(string $id, string $entryId, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->batch($id);
        try {
            $accept = 'accept' === $request->request->getString('decision');
            $this->batches->decideTypo($id, $entryId, $accept, $this->access->user());
            $this->addFlash('success', $accept ? 'Suggestion accepted: the corrected address was added to the batch; validate it before sending.' : 'Suggestion rejected: the address stays as entered.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($this->safeReturn($request, $this->generateUrl('dashboard_operator_batch_entries', ['id' => $id, 'filter' => 'typo_suspected'])));
    }

    #[Route('/{id}/approve', name: 'dashboard_operator_batch_approve', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function approve(string $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->batch($id);
        try {
            $this->batches->approveCompliance($id, $request->request->getString('reference'), $this->access->user());
            $this->addFlash('success', 'The compliance approval is recorded.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_batch', ['id' => $id]);
    }

    #[Route('/{id}/list-id', name: 'dashboard_operator_batch_list_id', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function listId(string $id, Request $request): RedirectResponse
    {
        $this->assertCsrf($request);
        $this->batch($id);
        try {
            $this->batches->setListId($id, $request->request->getString('list_id'), $this->access->user());
            $this->addFlash('success', 'List identifier saved.');
        } catch (DomainRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard_operator_batch', ['id' => $id]);
    }

    #[Route('/{id}/send', name: 'dashboard_operator_batch_send', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function send(string $id, Request $request, BatchSender $sender, DeliveryControl $delivery): Response
    {
        $batch = $this->batch($id);
        $client = $this->client((string) $batch['client_id']);
        $form = ['stage' => $request->request->getString('stage', $request->query->getString('stage', 'seed')),
            'count' => $request->request->getInt('count'), 'seed_addresses' => $request->request->getString('seed_addresses'),
            'subject' => $request->request->getString('subject'), 'text_body' => $request->request->getString('text_body'),
            'html_body' => $request->request->getString('html_body'), 'sender_email' => $request->request->getString('sender_email'),
            'sender_name' => $request->request->getString('sender_name'), 'track_opens' => $request->request->getBoolean('track_opens'),
            'track_clicks' => $request->request->getBoolean('track_clicks'), 'i_own_these' => $request->request->getBoolean('i_own_these')];
        $preview = null;
        $sample = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            try {
                if ('send' === $request->request->getString('do')) {
                    $jobId = $sender->send($batch, $client, $form, $this->access->user());
                    $this->addFlash('success', 'The send job was created and submitted. '.('LIVE' === $delivery->status()['mode']
                        ? 'The delivery daemon starts it within the configured rate limits.' : 'Delivery is '.$delivery->status()['mode'].': it waits until live delivery runs.'));

                    return $this->redirectToRoute('dashboard_operator_batch_send_report', ['id' => $id, 'jobId' => $jobId]);
                }
                $preview = $sender->preview($batch, $client, $form);
                [$subject, $text, $html] = BatchSender::content($form, 'repermission' === $batch['purpose'] && 'seed' !== $form['stage']);
                $first = $preview['recipients'][0]['address'] ?? 'recipient@example.org';
                $urls = ['{{response_url}}' => 'https://…/p/<personal link>', '{{confirm_url}}' => 'https://…/p/<personal link>?choice=confirm',
                    '{{unsubscribe_url}}' => 'https://…/p/<personal link>?choice=unsubscribe', '{{global_opt_out_url}}' => 'https://…/p/<personal link>?choice=global_opt_out'];
                $sample = ['subject' => BatchSender::render($subject, $urls, $first, false), 'text' => BatchSender::render($text, $urls, $first, false),
                    'html' => null === $html ? null : BatchSender::render($html, $urls, $first, true)];
            } catch (DomainRuleViolation $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('dashboard/operator/batches/send.html.twig', [
            'section' => 'batches', 'batch' => $batch, 'form' => $form, 'preview' => $preview, 'sample' => $sample,
            'summary' => $this->read->summary($id), 'delivery' => $delivery->status(), 'max_seed' => BatchSender::MAX_SEED,
            'domains' => $this->connection->fetchFirstColumn("SELECT domain FROM sending_domains WHERE client_id = ? AND status = 'verified' ORDER BY domain", [$batch['client_id']]),
        ]);
    }

    #[Route('/{id}/sends/{jobId}', name: 'dashboard_operator_batch_send_report', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}', 'jobId' => '[0-9a-f-]{36}'])]
    public function sendReport(string $id, string $jobId): Response
    {
        $batch = $this->batch($id);
        $send = array_values(array_filter($this->read->sends($id), static fn (array $s): bool => $s['send_job_id'] === $jobId))[0] ?? null;
        if (null === $send) {
            throw new NotFoundHttpException('Not found.');
        }

        return $this->render('dashboard/operator/batches/send_report.html.twig', [
            'section' => 'batches', 'batch' => $batch, 'send' => $send, 'report' => $this->read->sendReport($id, $jobId)]);
    }

    /** @return array<string, array<string, string>> */
    public static function labels(): array
    {
        return ['validation' => EntryStates::VALIDATION_RESULTS, 'eligibility' => EntryStates::ELIGIBILITY, 'consent' => EntryStates::CONSENT,
            'delivery' => EntryStates::DELIVERY, 'engagement' => EntryStates::ENGAGEMENT];
    }

    /** @return array{client: string, name: string, description: string, source: string, purpose: string, list_id: string} */
    private function batchForm(Request $request): array
    {
        return ['client' => $request->request->getString('client'), 'name' => $request->request->getString('name'),
            'description' => $request->request->getString('description'), 'source' => $request->request->getString('source'),
            'purpose' => $request->request->getString('purpose', 'validation'), 'list_id' => $request->request->getString('list_id')];
    }

    /** @return list<array<string, string>> */
    private function clients(): array
    {
        return $this->connection->fetchAllAssociative("SELECT id::text AS id, company_name, status FROM clients WHERE status <> 'closed' ORDER BY company_name");
    }

    private function client(string $id): Client
    {
        $client = Uuid::isValid($id) ? $this->em->find(Client::class, Uuid::fromString($id)) : null;

        return $client ?? throw new DomainRuleViolation('Choose the client on whose behalf the batch is validated and sent.');
    }

    /** @return array<string, mixed> */
    private function batch(string $id): array
    {
        try {
            return $this->read->batch($id);
        } catch (DomainRuleViolation) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function safeReturn(Request $request, string $default): string
    {
        $to = $request->request->getString('return_to');

        return 1 === preg_match('#^/dashboard/operator/batches/[A-Za-z0-9/_?=&%.-]*$#', $to) && !str_contains($to, '//') ? $to : $default;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ClientDashboardController::CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
