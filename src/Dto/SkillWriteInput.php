<?php

declare(strict_types=1);

namespace Escalated\Symfony\Dto;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Normalized create/update body for admin skills (snake_case wire contract).
 */
final class SkillWriteInput
{
    /**
     * @param list<int>                                   $routingTagIds
     * @param list<int>                                   $routingDepartmentIds
     * @param list<array{user_id: int, proficiency: int}> $agents
     */
    private function __construct(
        public readonly string $name,
        public readonly array $routingTagIds,
        public readonly array $routingDepartmentIds,
        public readonly array $agents,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromPayload(array $data): self
    {
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ('' === $name) {
            throw new UnprocessableEntityHttpException('name is required.');
        }
        if (\strlen($name) > 100) {
            throw new UnprocessableEntityHttpException('name must be at most 100 characters.');
        }

        $routingTagIds = self::intList($data['routing_tag_ids'] ?? []);
        $routingDepartmentIds = self::intList($data['routing_department_ids'] ?? []);
        $agents = self::agentsList($data['agents'] ?? []);

        return new self($name, $routingTagIds, $routingDepartmentIds, $agents);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new UnprocessableEntityHttpException('routing ids must be arrays of integers.');
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_numeric($v)) {
                throw new UnprocessableEntityHttpException('routing ids must be integers.');
            }
            $out[] = (int) $v;
        }

        return $out;
    }

    /**
     * @return list<array{user_id: int, proficiency: int}>
     */
    private static function agentsList(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new UnprocessableEntityHttpException('agents must be an array.');
        }
        $out = [];
        foreach ($raw as $row) {
            if (!\is_array($row)) {
                throw new UnprocessableEntityHttpException('each agent entry must be an object.');
            }
            if (!isset($row['user_id']) || !is_numeric($row['user_id'])) {
                throw new UnprocessableEntityHttpException('each agent requires user_id.');
            }
            $uid = (int) $row['user_id'];
            $prof = isset($row['proficiency']) && is_numeric($row['proficiency'])
                ? (int) $row['proficiency']
                : 3;
            if ($prof < 1 || $prof > 5) {
                throw new UnprocessableEntityHttpException('proficiency must be between 1 and 5.');
            }
            $out[] = ['user_id' => $uid, 'proficiency' => $prof];
        }

        return $out;
    }
}
