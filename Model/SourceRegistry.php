<?php

namespace Kanboard\Plugin\Pulse\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Pulse\Model\Source\ManualTimeSource;
use Kanboard\Plugin\Pulse\Model\Source\ProjectActivitySource;
use Kanboard\Plugin\Pulse\Model\Source\TimerSource;

/**
 * Built-in activity sources plus any a plugin registers on the `pulse:sources` hook.
 */
class SourceRegistry extends Base
{
    /** @return list<ActivitySource> */
    public function all(): array
    {
        return array_merge($this->builtins(), $this->thirdParty());
    }

    /** @return list<ActivitySource> */
    protected function builtins(): array
    {
        return [
            new ProjectActivitySource($this->container),
            new TimerSource($this->container),
            new ManualTimeSource($this->container),
        ];
    }

    /**
     * Sources registered on `pulse:sources`. A listener that throws or returns something
     * else is skipped, so a broken plugin cannot take the activity page down.
     *
     * @return list<ActivitySource>
     */
    private function thirdParty(): array
    {
        $sources = [];
        foreach ($this->hook->getListeners('pulse:sources') as $listener) {
            try {
                $source = $listener();
            } catch (\Throwable $e) {
                $this->logger->error('Pulse: a pulse:sources listener failed: '.$e->getMessage());
                continue;
            }
            if ($source instanceof ActivitySource) {
                $sources[] = $source;
            } else {
                $this->logger->debug('Pulse: ignored a pulse:sources listener that did not return an ActivitySource ('.get_debug_type($source).')');
            }
        }

        return $sources;
    }

    /**
     * Rows from every source; no projects means no rows. Every row passes one gate (see
     * inScope()). Third-party sources are untrusted: one whose fetch() throws loses only its own
     * rows. Built-in sources are not guarded, so their bugs surface.
     *
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}>
     */
    public function fetchAll(array $userIds, array $projectIds, int $from, int $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = [];
        foreach ($this->builtins() as $source) {
            array_push($rows, ...$this->inScope($source->fetch($userIds, $projectIds, $from, $to), $userIds, $projectIds, $from, $to));
        }
        foreach ($this->thirdParty() as $source) {
            try {
                array_push($rows, ...$this->inScope($source->fetch($userIds, $projectIds, $from, $to), $userIds, $projectIds, $from, $to));
            } catch (\Throwable $e) {
                $this->logger->error('Pulse: activity source '.$source::class.' failed: '.$e->getMessage());
            }
        }

        return $rows;
    }

    /**
     * Keeps well-formed rows inside the requested range and projects (and users, when given),
     * normalised to the row shape. Drops rows with a non-positive or non-numeric id, a missing or
     * non-numeric ts, or a non-scalar or empty type.
     *
     * @param list<array> $rows
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}>
     */
    private function inScope(array $rows, array $userIds, array $projectIds, int $from, int $to): array
    {
        $projects = array_flip(array_map('intval', $projectIds));
        $users = array_flip(array_map('intval', $userIds));

        $kept = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $userId = $this->positiveInt($row['user_id'] ?? null);
            $projectId = $this->positiveInt($row['project_id'] ?? null);
            $ts = $row['ts'] ?? null;
            $type = $row['type'] ?? null;
            if ($userId === null || $projectId === null || ! is_numeric($ts) || ! is_scalar($type) || (string) $type === '') {
                continue;
            }
            $ts = (int) $ts;
            if ($ts < $from || $ts > $to || ! isset($projects[$projectId]) || ($users !== [] && ! isset($users[$userId]))) {
                continue;
            }
            $kept[] = [
                'user_id'    => $userId,
                'project_id' => $projectId,
                'ts'         => $ts,
                'type'       => (string) $type,
                'count'      => (int) ($row['count'] ?? 0),
                'hours'      => (float) ($row['hours'] ?? 0),
            ];
        }

        return $kept;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
