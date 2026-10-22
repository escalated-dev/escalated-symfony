<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Contract\ImportAdapterInterface;
use Escalated\Symfony\Entity\ImportJob;
use Escalated\Symfony\Import\ImportAdapterRegistry;
use Escalated\Symfony\Import\ImportCredentials;
use Escalated\Symfony\Import\ImportDispatcherInterface;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\UserKeyResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin Import wizard, as escalated-laravel's Admin\ImportController, on
 * its routes and with its props:
 *
 *   Index     GET  /admin/import                            adapters, recent jobs
 *   Connect   GET  /admin/import/{platform}/connect         credential form
 *             POST /admin/import/{platform}/test-connection JSON {success[, error]}
 *   Mapping   GET  /admin/import/{platform}/mapping         defaults + source fields
 *   Review    POST /admin/import/{platform}/review          echo of the choices; writes nothing
 *             POST /admin/import/{platform}/start           creates the job and queues the run
 *   Progress  GET  /admin/import/progress/{jobId}
 *             GET  /admin/import/status/{jobId}             JSON poll
 *             POST /admin/import/{pause,resume,cancel}/{jobId}
 *
 * Sources are the services implementing ImportAdapterInterface; the bundle
 * ships none. Tested credentials wait in the session, sealed, until the
 * import starts, and are never sent to the browser.
 */
#[Route('/admin/import', name: 'escalated.admin.import.')]
class ImportController extends AbstractController
{
    private const PLATFORM = '[A-Za-z0-9_.-]+';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly ImportAdapterRegistry $adapters,
        private readonly ImportCredentials $credentials,
        private readonly ImportDispatcherInterface $dispatcher,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $jobs = $this->em->getRepository(ImportJob::class)->findBy([], ['createdAt' => 'DESC'], 20);

        return $this->renderer->render('Escalated/Admin/Import/Index', [
            'adapters' => array_map(static fn (ImportAdapterInterface $a): array => [
                'name' => $a->name(),
                'display_name' => $a->displayName(),
                'credential_fields' => $a->credentialFields(),
            ], $this->adapters->all()),
            'jobs' => array_map([$this, 'serialize'], $jobs),
        ]);
    }

    #[Route('/progress/{jobId}', name: 'progress', methods: ['GET'])]
    public function progress(string $jobId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Import/Progress', [
            'job' => $this->serialize($this->job($jobId)),
        ]);
    }

    #[Route('/status/{jobId}', name: 'status', methods: ['GET'])]
    public function status(string $jobId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $job = $this->job($jobId);

        return new JsonResponse([
            'status' => $job->getStatus(),
            'progress' => (object) $job->getProgress(),
            'error_log' => \array_slice($job->getErrorLog(), -50),
            'started_at' => $job->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $job->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/pause/{jobId}', name: 'pause', methods: ['POST'])]
    public function pause(string $jobId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $job = $this->job($jobId);

        if ('importing' === $job->getStatus()) {
            $job->setStatus('paused');
            $this->em->flush();
        }
        $this->addFlash('success', 'Import paused.');

        return $this->toProgress($job);
    }

    #[Route('/resume/{jobId}', name: 'resume', methods: ['POST'])]
    public function resume(string $jobId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $job = $this->job($jobId);

        if ($job->isResumable()) {
            if ('failed' === $job->getStatus()) {
                $job->transitionTo('mapping');
                $this->em->flush();
            }
            $this->dispatcher->dispatch($job);
        }
        $this->addFlash('success', 'Import resumed.');

        return $this->toProgress($job);
    }

    #[Route('/cancel/{jobId}', name: 'cancel', methods: ['POST'])]
    public function cancel(string $jobId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $job = $this->job($jobId);

        $job->setStatus('failed')
            ->setCompletedAt(new \DateTimeImmutable())
            ->setSealedCredentials(null);
        $this->em->flush();
        $this->addFlash('success', 'Import cancelled.');

        return $this->toProgress($job);
    }

    #[Route('/{platform}/connect', name: 'connect', methods: ['GET'], requirements: ['platform' => self::PLATFORM])]
    public function connect(string $platform): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $adapter = $this->adapter($platform);

        return $this->renderer->render('Escalated/Admin/Import/Connect', [
            'platform' => $adapter->name(),
            'display_name' => $adapter->displayName(),
            'credential_fields' => $adapter->credentialFields(),
        ]);
    }

    #[Route('/{platform}/test-connection', name: 'test-connection', methods: ['POST'], requirements: ['platform' => self::PLATFORM])]
    public function testConnection(string $platform, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $adapter = $this->adapter($platform);

        $posted = $this->payload($request)['credentials'] ?? [];
        $credentials = [];
        foreach (\is_array($posted) ? $posted : [] as $key => $value) {
            $credentials[(string) $key] = \is_scalar($value) ? (string) $value : '';
        }

        try {
            $success = $adapter->testConnection($credentials);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }

        if ($success) {
            $request->getSession()->set($this->sessionKey($adapter), $this->credentials->seal($credentials));
        }

        return new JsonResponse(['success' => $success]);
    }

    #[Route('/{platform}/mapping', name: 'mapping', methods: ['GET'], requirements: ['platform' => self::PLATFORM])]
    public function mapping(string $platform, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $adapter = $this->adapter($platform);

        $credentials = $this->storedCredentials($request, $adapter);
        if (null === $credentials) {
            return $this->toConnect($adapter);
        }

        $mappings = [];
        foreach ($adapter->entityTypes() as $type) {
            $mappings[$type] = [
                'defaults' => (object) $adapter->defaultFieldMappings($type),
                'source_fields' => $adapter->availableSourceFields($type, $credentials),
            ];
        }

        return $this->renderer->render('Escalated/Admin/Import/Mapping', [
            'platform' => $adapter->name(),
            'display_name' => $adapter->displayName(),
            'entity_types' => $adapter->entityTypes(),
            'mappings' => (object) $mappings,
        ]);
    }

    #[Route('/{platform}/review', name: 'review', methods: ['POST'], requirements: ['platform' => self::PLATFORM])]
    public function review(string $platform, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $payload = $this->payload($request);
        $types = $payload['entity_types'] ?? [];

        return $this->renderer->render('Escalated/Admin/Import/Review', [
            'platform' => $platform,
            'field_mappings' => (object) (\is_array($payload['field_mappings'] ?? null) ? $payload['field_mappings'] : []),
            'entity_types' => array_values(array_map('strval', \is_array($types) ? $types : [])),
        ]);
    }

    #[Route('/{platform}/start', name: 'start', methods: ['POST'], requirements: ['platform' => self::PLATFORM])]
    public function start(string $platform, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');
        $adapter = $this->adapter($platform);

        $credentials = $this->storedCredentials($request, $adapter);
        if (null === $credentials) {
            return $this->toConnect($adapter);
        }

        $mappings = $this->payload($request)['field_mappings'] ?? [];
        $user = $this->getUser();
        $job = (new ImportJob($adapter->name(), 'mapping'))
            ->setUserId(null === $user ? null : $this->userKeys->resolve($user))
            ->setSealedCredentials($this->credentials->seal($credentials))
            ->setFieldMappings(\is_array($mappings) ? $mappings : []);
        $this->em->persist($job);
        $this->em->flush();

        // The job holds them now, sealed.
        $request->getSession()->remove($this->sessionKey($adapter));

        $this->dispatcher->dispatch($job);

        return $this->toProgress($job);
    }

    /**
     * The fields the import pages read, as escalated-laravel's
     * ImportJob::toPageProps(). Built from an explicit list so a field added
     * later is not sent to the browser by default; the credentials are a
     * secret the import run reads server-side and are never sent.
     *
     * @return array<string, mixed>
     */
    private function serialize(ImportJob $job): array
    {
        return [
            'id' => $job->getId(),
            'platform' => $job->getPlatform(),
            'status' => $job->getStatus(),
            'progress' => (object) $job->getProgress(),
            'field_mappings' => (object) $job->getFieldMappings(),
            'error_log' => \array_slice($job->getErrorLog(), -50),
            'started_at' => $job->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $job->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function adapter(string $platform): ImportAdapterInterface
    {
        return $this->adapters->find($platform)
            ?? throw $this->createNotFoundException(sprintf('Import adapter not found: %s', $platform));
    }

    private function job(string $jobId): ImportJob
    {
        $job = $this->em->find(ImportJob::class, $jobId);
        if (!$job instanceof ImportJob) {
            throw $this->createNotFoundException('Import job not found.');
        }

        return $job;
    }

    private function sessionKey(ImportAdapterInterface $adapter): string
    {
        return 'escalated.import.'.$adapter->name().'.credentials';
    }

    /** @return array<string, string>|null */
    private function storedCredentials(Request $request, ImportAdapterInterface $adapter): ?array
    {
        $sealed = $request->getSession()->get($this->sessionKey($adapter));

        return \is_string($sealed) ? $this->credentials->open($sealed) : null;
    }

    private function toConnect(ImportAdapterInterface $adapter): Response
    {
        $this->addFlash('error', 'Credentials expired. Please reconnect.');

        return $this->redirectToRoute('escalated.admin.import.connect', ['platform' => $adapter->name()]);
    }

    private function toProgress(ImportJob $job): Response
    {
        return $this->redirectToRoute('escalated.admin.import.progress', ['jobId' => $job->getId()]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
