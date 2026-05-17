<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Dto;

use Escalated\Symfony\Dto\SkillWriteInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SkillWriteInputTest extends TestCase
{
    public function testFromPayloadNormalizesAgentsAndRouting(): void
    {
        $dto = SkillWriteInput::fromPayload([
            'name' => 'Networking',
            'routing_tag_ids' => [1, 2],
            'routing_department_ids' => [9],
            'agents' => [
                ['user_id' => 42],
                ['user_id' => 7, 'proficiency' => 5],
            ],
        ]);

        $this->assertSame('Networking', $dto->name);
        $this->assertSame([1, 2], $dto->routingTagIds);
        $this->assertSame([9], $dto->routingDepartmentIds);
        $this->assertSame(42, $dto->agents[0]['user_id']);
        $this->assertSame(3, $dto->agents[0]['proficiency']);
        $this->assertSame(5, $dto->agents[1]['proficiency']);
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        SkillWriteInput::fromPayload(['name' => '   ']);
    }

    public function testProficiencyOutOfRangeThrows(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        SkillWriteInput::fromPayload([
            'name' => 'X',
            'agents' => [['user_id' => 1, 'proficiency' => 99]],
        ]);
    }
}
