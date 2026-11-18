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
    private function builtins(): array
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
     * Rows from every source; no projects means no rows. Third-party sources are untrusted:
     * one whose fetch() throws loses only its own rows, and rows outside the requested
     * projects (or users, when given) are dropped. Built-in sources are not guarded, so
     * their bugs surface.
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
            array_push($rows, ...$source->fetch($userIds, $projectIds, $from, $to));
        }
        foreach ($this->thirdParty() as $source) {
            try {
                array_push($rows, ...$this->inScope($source->fetch($userIds, $projectIds, $from, $to), $userIds, $projectIds));
            } catch (\Throwable $e) {
                $this->logger->error('Pulse: activity source '.$source::class.' failed: '.$e->getMessage());
            }
        }

        return $rows;
    }

    /**
     * Keeps rows whose project is requested and, when users are given, whose user is too.
     *
     * @param list<array> $rows
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return list<array>
     */
    private function inScope(array $rows, array $userIds, array $projectIds): array
    {
        $projects = array_flip($projectIds);
        $users = array_flip($userIds);

        return array_values(array_filter($rows, fn (array $row) => isset($projects[$row['project_id'] ?? ''])
            && ($userIds === [] || isset($users[$row['user_id'] ?? '']))));
    }
}
