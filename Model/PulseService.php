<?php

namespace Kanboard\Plugin\Pulse\Model;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectModel;

/**
 * Builds the graphs a page shows: scope check, one fetch, one heatmap per person, colours.
 */
class PulseService extends Base
{
    /** Tip order for known types; unknown types follow, alphabetically. */
    private const TYPE_ORDER = ['move', 'task', 'comment', 'subtask', 'file'];

    /**
     * Your page: you (or, for an admin, the picked person) plus owned agents.
     *
     * @return array{graphs:list<array>, projects:array<int,string>, project_id:?int, user_colors:array<int,string>, project_colors:array<int,string>}
     * @throws \Kanboard\Core\Controller\AccessForbiddenException
     */
    public function userGraphs(int $viewerId, ?int $targetUserId, ?int $projectId): array
    {
        $scope = $this->scopeResolver()->forUserPage($viewerId, $targetUserId, $projectId);
        // A filter outside the visible set was ignored by the resolver: show "all projects" then.
        $selected = $projectId !== null && $scope['project_ids'] === [$projectId] ? $projectId : null;

        return $this->graphs($scope['user_ids'], $scope['project_ids'], $scope['projects'], $selected);
    }

    /**
     * Project page: one graph per user with activity in the project, busiest first.
     *
     * @return array{graphs:list<array>, projects:array<int,string>, project_id:?int, user_colors:array<int,string>, project_colors:array<int,string>}
     * @throws \Kanboard\Core\Controller\AccessForbiddenException
     */
    public function projectGraphs(int $viewerId, int $projectId): array
    {
        $scope = $this->scopeResolver()->forProjectPage($viewerId, $projectId);
        $name = (string) $this->db->table(ProjectModel::TABLE)->eq('id', $projectId)->findOneColumn('name');

        return $this->graphs(null, $scope['project_ids'], [$projectId => $name], $projectId);
    }

    /**
     * @param list<int>|null $userIds null = every user found in the rows, ordered by total desc
     * @param list<int> $projectIds
     * @param array<int,string> $projects
     */
    private function graphs(?array $userIds, array $projectIds, array $projects, ?int $selectedProject): array
    {
        $tz = new \DateTimeZone($this->timezoneModel->getCurrentTimezone());
        $today = new \DateTimeImmutable('now', $tz);
        $range = HeatmapBuilder::range($today);
        $rows = (new SourceRegistry($this->container))->fetchAll($userIds ?? [], $projectIds, $range['from'], $range['to']);

        $ids = $userIds ?? array_values(array_unique(array_map(fn ($row) => (int) $row['user_id'], $rows)));
        $graphs = [];
        foreach ($ids as $id) {
            $graph = HeatmapBuilder::build($rows, $id, $tz, $today);
            $graph['name'] = $this->userName($id);
            $graphs[] = $graph;
        }

        if ($userIds === null) {
            usort($graphs, fn ($a, $b) => [$b['total'], $a['user_id']] <=> [$a['total'], $b['user_id']]);
            $graphs = array_values(array_filter($graphs, fn ($graph) => $graph['total'] > 0));
        }

        $projectWeights = [];
        foreach ($rows as $row) {
            $pid = (int) $row['project_id'];
            $projectWeights[$pid] = ($projectWeights[$pid] ?? 0) + (int) $row['count'] + (float) $row['hours'];
        }

        return [
            'graphs' => $graphs,
            'projects' => $projects,
            'project_id' => $selectedProject,
            'user_colors' => Palette::assign(array_column($graphs, 'total', 'user_id')),
            'project_colors' => Palette::assign($projectWeights),
        ];
    }

    private function userName(int $userId): string
    {
        $user = $this->userModel->getById($userId);

        return $user ? (string) ($user['name'] ?: $user['username']) : '#'.$userId;
    }

    private function scopeResolver(): ScopeResolver
    {
        return new ScopeResolver($this->container);
    }

    /**
     * Plain-text breakdown of one day, e.g. "Nov 3, 2026 · 4 moves, 3 comments, 1.5 h logged · Website, Ops".
     * Not escaped: the template escapes it.
     *
     * @param array{types:array<string,array{count:int,hours:float}>, projects:array<int,float>} $day
     * @param array<int,string> $projectNames
     */
    public static function dayTip(string $date, array $day, array $projectNames): string
    {
        $label = (new \DateTimeImmutable($date))->format('M j, Y');

        $types = $day['types'];
        uksort($types, function ($a, $b) {
            $ra = array_search($a, self::TYPE_ORDER, true);
            $rb = array_search($b, self::TYPE_ORDER, true);
            return [$ra === false ? 1 : 0, $ra === false ? $a : $ra] <=> [$rb === false ? 1 : 0, $rb === false ? $b : $rb];
        });

        $parts = [];
        $hours = 0.0;
        foreach ($types as $type => $totals) {
            $count = (int) $totals['count'];
            $hours += (float) $totals['hours'];
            if ($count > 0) {
                $parts[] = self::countLabel((string) $type, $count);
            }
        }
        if ($hours > 0) {
            $parts[] = t('%s logged', self::hours($hours));
        }

        if ($parts === []) {
            return $label.' · '.t('No activity');
        }

        $projects = $day['projects'];
        arsort($projects);
        $names = [];
        foreach (array_keys($projects) as $pid) {
            $names[] = $projectNames[$pid] ?? '#'.$pid;
        }

        return $label.' · '.implode(', ', $parts).($names === [] ? '' : ' · '.implode(', ', $names));
    }

    /** Hours for display: one decimal, ".0" dropped; "<0.1 h" when some time rounds away. */
    public static function hours(float $hours): string
    {
        $rounded = round($hours, 1);
        if ($hours > 0 && $rounded == 0) {
            return t('<0.1 h');
        }

        return t('%s h', $rounded == (int) $rounded ? (string) (int) $rounded : number_format($rounded, 1));
    }

    private static function countLabel(string $type, int $count): string
    {
        return match ($type) {
            'move' => $count === 1 ? t('1 move') : t('%d moves', $count),
            'task' => $count === 1 ? t('1 task event') : t('%d task events', $count),
            'comment' => $count === 1 ? t('1 comment') : t('%d comments', $count),
            'subtask' => $count === 1 ? t('1 subtask') : t('%d subtasks', $count),
            'file' => $count === 1 ? t('1 file') : t('%d files', $count),
            default => $count.' '.$type,
        };
    }
}
