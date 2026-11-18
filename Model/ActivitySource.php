<?php

namespace Kanboard\Plugin\Pulse\Model;

/**
 * A producer of typed activity rows for the heatmap.
 *
 * `$userIds === []` means "any user"; `$projectIds === []` returns [] without querying.
 */
interface ActivitySource
{
    /** @return list<string> */
    public function types(): array;

    /**
     * @param list<int> $userIds
     * @param list<int> $projectIds
     * @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}>
     */
    public function fetch(array $userIds, array $projectIds, int $from, int $to): array;
}
