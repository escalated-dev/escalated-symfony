<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\EscalatedSetting;
use Escalated\Symfony\Repository\EscalatedSettingRepository;
use Escalated\Symfony\Service\SettingsService;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private EscalatedSettingRepository $repo;
    private SettingsService $service;

    /** @var array<string, EscalatedSetting> */
    private array $store;

    protected function setUp(): void
    {
        $this->store = [];

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function (EscalatedSetting $s): void {
            $this->store[$s->getKey()] = $s;
        });
        $this->em->method('flush');
        $this->em->method('remove')->willReturnCallback(function (EscalatedSetting $s): void {
            unset($this->store[$s->getKey()]);
        });

        $this->repo = $this->createMock(EscalatedSettingRepository::class);
        $this->repo->method('find')->willReturnCallback(
            fn (string $key): ?EscalatedSetting => $this->store[$key] ?? null
        );

        $this->service = new SettingsService($this->em, $this->repo);
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertNull($this->service->get('unset_key'));
        $this->assertSame('fallback', $this->service->get('unset_key', 'fallback'));
    }

    public function testSetInsertsNewRow(): void
    {
        $this->service->set('foo', 'bar');

        $this->assertArrayHasKey('foo', $this->store);
        $this->assertSame('bar', $this->store['foo']->getValue());
    }

    public function testSetUpdatesExistingRow(): void
    {
        $this->store['foo'] = new EscalatedSetting('foo', 'old');

        $this->service->set('foo', 'new');

        $this->assertSame('new', $this->store['foo']->getValue());
    }

    public function testGetBoolParsesTruthyValues(): void
    {
        $this->store['flag'] = new EscalatedSetting('flag', '1');
        $this->assertTrue($this->service->getBool('flag'));

        $this->store['flag']->setValue('true');
        $this->assertTrue($this->service->getBool('flag'));

        $this->store['flag']->setValue('0');
        $this->assertFalse($this->service->getBool('flag'));

        $this->assertTrue($this->service->getBool('absent', true));
    }

    public function testGetIntFallsBackOnNonNumeric(): void
    {
        $this->store['count'] = new EscalatedSetting('count', 'abc');
        $this->assertSame(42, $this->service->getInt('count', 42));

        $this->store['count']->setValue('7');
        $this->assertSame(7, $this->service->getInt('count'));
    }

    public function testDeleteRemovesKey(): void
    {
        $this->store['foo'] = new EscalatedSetting('foo', 'bar');

        $this->service->delete('foo');

        $this->assertArrayNotHasKey('foo', $this->store);
    }

    public function testDeleteIsNoopWhenKeyAbsent(): void
    {
        $this->service->delete('never_existed');

        $this->assertSame([], $this->store);
    }
}
