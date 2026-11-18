<?php

namespace Kanboard\Plugin\Pulse\Model\Source;

use Kanboard\Core\Base;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use Kanboard\Plugin\Pulse\Model\ActivitySource;
use PicoDb\SQLException;

/**
 * Hours typed in by hand, from TimeReport's manual time ledger, dated by entry.
 * Without TimeReport installed the ledger table is absent and this yields nothing.
 */
class ManualTimeSource extends Base implements ActivitySource
{
    /** TimeReport's ManualTimeLog::TABLE, named here so Pulse does not need TimeReport loaded. */
    private const TABLE = 'timereport_manual_time';

    public function types(): array
    {
        return ['time'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        if ($projectIds === [] || ! $this->ledgerExists()) {
            return [];
        }

        $enteredAt = $this->db->escapeIdentifier('entered_at', self::TABLE);
        $userId = $this->db->escapeIdentifier('user_id', self::TABLE);
        $projectId = $this->db->escapeIdentifier('project_id', TaskModel::TABLE);

        $query = $this->db->table(self::TABLE)
            ->columns($userId, $projectId, $enteredAt, $this->db->escapeIdentifier('hours', self::TABLE))
            ->join(SubtaskModel::TABLE, 'id', 'subtask_id')
            ->join(TaskModel::TABLE, 'id', 'task_id', SubtaskModel::TABLE)
            ->in($projectId, $projectIds)
            // entered_at 0 marks time that predates the ledger: it has no date to place.
            ->gt($enteredAt, 0)
            ->gte($enteredAt, $from)
            ->lte($enteredAt, $to)
            ->asc($enteredAt)
            ->asc($this->db->escapeIdentifier('id', self::TABLE));
        if ($userIds !== []) {
            $query->in($userId, $userIds);
        }

        return array_map(fn (array $entry) => [
            'user_id'    => (int) $entry['user_id'],
            'project_id' => (int) $entry['project_id'],
            'ts'         => (int) $entry['entered_at'],
            'type'       => 'time',
            'count'      => 0,
            'hours'      => (float) $entry['hours'],
        ], $query->findAll());
    }

    /** Driver-agnostic probe: selecting from a missing table raises SQLException. */
    private function ledgerExists(): bool
    {
        try {
            $this->db->table(self::TABLE)->columns('id')->limit(1)->findAll();
            return true;
        } catch (SQLException) {
            return false;
        }
    }
}
