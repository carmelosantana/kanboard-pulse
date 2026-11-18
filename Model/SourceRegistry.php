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
        $sources = [
            new ProjectActivitySource($this->container),
            new TimerSource($this->container),
            new ManualTimeSource($this->container),
        ];

        foreach ($this->hook->getListeners('pulse:sources') as $listener) {
            $source = $listener();
            if ($source instanceof ActivitySource) {
                $sources[] = $source;
            } else {
                $this->logger->debug('Pulse: ignored a pulse:sources listener that did not return an ActivitySource ('.get_debug_type($source).')');
            }
        }

        return $sources;
    }

    /**
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}>
     */
    public function fetchAll(array $userIds, array $projectIds, int $from, int $to): array
    {
        $rows = [];
        foreach ($this->all() as $source) {
            array_push($rows, ...$source->fetch($userIds, $projectIds, $from, $to));
        }

        return $rows;
    }
}
