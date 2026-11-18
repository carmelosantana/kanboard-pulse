<?php

namespace Kanboard\Plugin\Pulse\Model\Source;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectActivityModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Plugin\Pulse\Model\ActivitySource;

/**
 * Turns core's project_activities rows into typed activity rows.
 */
class ProjectActivitySource extends Base implements ActivitySource
{
    private const EVENT_TYPES = [
        'task.move.column'   => 'move',
        'task.move.position' => 'move',
        'task.move.swimlane' => 'move',
        'task.move.project'  => 'move',
        'task.create'        => 'task',
        'task.close'         => 'task',
        'task.open'          => 'task',
        'comment.create'     => 'comment',
        'subtask.create'     => 'subtask',
        'task.file.create'   => 'file',
    ];

    public function types(): array
    {
        return ['move', 'task', 'comment', 'subtask', 'file'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = [];
        $seenMoves = [];

        $events = $this->query($userIds, $projectIds)
            ->columns('creator_id', 'project_id', 'task_id', 'event_name', 'date_creation')
            ->in('event_name', array_keys(self::EVENT_TYPES))
            ->gte('date_creation', $from)
            ->lte('date_creation', $to)
            ->asc('date_creation')
            ->asc('id')
            ->findAll();

        foreach ($events as $event) {
            $type = self::EVENT_TYPES[$event['event_name']];

            if ($type === 'move') {
                // One drag can write several task.move.* rows in the same second.
                $key = $event['creator_id'].':'.$event['task_id'].':'.$event['date_creation'];
                if (isset($seenMoves[$key])) {
                    continue;
                }
                $seenMoves[$key] = true;
            }

            $rows[] = $this->row($event, $type);
        }

        foreach ($this->completions($userIds, $projectIds, $from, $to) as $event) {
            $rows[] = $this->row($event, 'subtask');
        }

        usort($rows, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        return $rows;
    }

    /**
     * One completion per subtask: its earliest done snapshot by any user, at any date, so later
     * edits of a done subtask are not re-counted. Credited to that row's creator, then filtered.
     *
     * @return list<array>
     */
    private function completions(array $userIds, array $projectIds, int $from, int $to): array
    {
        // Only tasks with a done snapshot in range can hold an in-range completion.
        $candidates = $this->query($userIds, $projectIds)
            ->columns('task_id', 'data')
            ->eq('event_name', 'subtask.update')
            ->gte('date_creation', $from)
            ->lte('date_creation', $to)
            ->findAll();

        $taskIds = [];
        foreach ($candidates as $event) {
            if ($this->doneSubtaskId((string) $event['data']) !== null) {
                $taskIds[(int) $event['task_id']] = true;
            }
        }
        if ($taskIds === []) {
            return [];
        }

        $updates = $this->query([], $projectIds)
            ->columns('creator_id', 'project_id', 'date_creation', 'data')
            ->eq('event_name', 'subtask.update')
            ->in('task_id', array_keys($taskIds))
            ->lte('date_creation', $to)
            ->asc('date_creation')
            ->asc('id')
            ->findAll();

        $first = [];
        foreach ($updates as $event) {
            $subtaskId = $this->doneSubtaskId((string) $event['data']);
            if ($subtaskId !== null && ! isset($first[$subtaskId])) {
                $first[$subtaskId] = $event;
            }
        }

        return array_values(array_filter($first, fn (array $event) =>
            (int) $event['date_creation'] >= $from
            && ($userIds === [] || in_array((int) $event['creator_id'], array_map('intval', $userIds), true))
        ));
    }

    /** The subtask id when the row's snapshot is done, else null (including undecodable rows). */
    private function doneSubtaskId(string $data): ?int
    {
        $subtask = $this->decode($data)['subtask'] ?? null;
        if (! is_array($subtask) || ! isset($subtask['id'], $subtask['status'])) {
            return null;
        }
        return (int) $subtask['status'] === SubtaskModel::STATUS_DONE ? (int) $subtask['id'] : null;
    }

    private function query(array $userIds, array $projectIds)
    {
        $query = $this->db->table(ProjectActivityModel::TABLE)->in('project_id', $projectIds);
        if ($userIds !== []) {
            $query->in('creator_id', $userIds);
        }
        return $query;
    }

    /** Core stores JSON; older installs hold PHP-serialized data. */
    private function decode(string $data): ?array
    {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $decoded = @unserialize($data, ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : null;
    }

    private function row(array $event, string $type): array
    {
        return [
            'user_id'    => (int) $event['creator_id'],
            'project_id' => (int) $event['project_id'],
            'ts'         => (int) $event['date_creation'],
            'type'       => $type,
            'count'      => 1,
            'hours'      => 0.0,
        ];
    }
}
