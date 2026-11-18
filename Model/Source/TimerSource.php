<?php

namespace Kanboard\Plugin\Pulse\Model\Source;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\SubtaskTimeTrackingModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Pulse\Model\ActivitySource;

/**
 * Hours from core's finished subtask timers, dated by when each timer stopped.
 */
class TimerSource extends Base implements ActivitySource
{
    public function types(): array
    {
        return ['time'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        $table = SubtaskTimeTrackingModel::TABLE;
        $end = $this->db->escapeIdentifier('end', $table);
        $userId = $this->db->escapeIdentifier('user_id', $table);
        $projectId = $this->db->escapeIdentifier('project_id', TaskModel::TABLE);

        $query = $this->db->table($table)
            ->columns($userId, $projectId, $this->db->escapeIdentifier('start', $table), $end, $this->db->escapeIdentifier('time_spent', $table))
            ->join(SubtaskModel::TABLE, 'id', 'subtask_id')
            ->join(TaskModel::TABLE, 'id', 'task_id', SubtaskModel::TABLE)
            ->in($projectId, $projectIds)
            ->gt($end, 0)
            ->gte($end, $from)
            ->lte($end, $to)
            ->asc($end)
            ->asc($this->db->escapeIdentifier('id', $table));
        if ($userIds !== []) {
            $query->in($userId, $userIds);
        }

        return array_map(fn (array $timer) => [
            'user_id'    => (int) $timer['user_id'],
            'project_id' => (int) $timer['project_id'],
            'ts'         => (int) $timer['end'],
            'type'       => 'time',
            'count'      => 0,
            'hours'      => $this->hours($timer),
        ], $query->findAll());
    }

    /** time_spent, or the start-to-end span when it is 0 (TimeReport's rowHours parity). */
    private function hours(array $timer): float
    {
        $timeSpent = (float) $timer['time_spent'];
        if ($timeSpent > 0) {
            return $timeSpent;
        }
        $start = (int) $timer['start'];
        $end = (int) $timer['end'];

        return $end > $start ? ($end - $start) / 3600 : 0.0;
    }
}
