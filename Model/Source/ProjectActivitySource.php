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

        foreach ($this->completions($userIds, $projectIds) as $event) {
            $ts = (int) $event['date_creation'];
            if ($ts >= $from && $ts <= $to) {
                $rows[] = $this->row($event, 'subtask');
            }
        }

        usort($rows, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        return $rows;
    }

    /**
     * The earliest done snapshot per subtask, at any date: later edits of a done subtask are not re-counted.
     *
     * @return list<array>
     */
    private function completions(array $userIds, array $projectIds): array
    {
        $updates = $this->query($userIds, $projectIds)
            ->columns('creator_id', 'project_id', 'date_creation', 'data')
            ->eq('event_name', 'subtask.update')
            ->asc('date_creation')
            ->asc('id')
            ->findAll();

        $first = [];
        foreach ($updates as $event) {
            $subtask = $this->decode((string) $event['data'])['subtask'] ?? null;
            if (! is_array($subtask) || ! isset($subtask['id'], $subtask['status'])) {
                continue;
            }
            if ((int) $subtask['status'] !== SubtaskModel::STATUS_DONE || isset($first[$subtask['id']])) {
                continue;
            }
            $first[$subtask['id']] = $event;
        }

        return array_values($first);
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
