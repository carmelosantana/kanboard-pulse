<?php

namespace Kanboard\Plugin\Pulse\Model;

use Kanboard\Core\Base;
use Kanboard\Model\ProjectModel;

/**
 * Builds the graphs a page shows: scope check, one fetch, one heatmap per person, colours.
 */
class PulseService extends Base
{
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
     * Plain text (e() not t(), so nothing is escaped twice): the template escapes it.
     *
     * @param array{types:array<string,array{count:int,hours:float}>, projects:array<int,float>} $day
     * @param array<int,string> $projectNames
     */
    public static function dayTip(string $date, array $day, array $projectNames): string
    {
        $label = (new \DateTimeImmutable($date))->format('M j, Y');

        $types = $day['types'];
        uksort($types, fn ($a, $b) => HeatmapBuilder::typeRank((string) $a) <=> HeatmapBuilder::typeRank((string) $b));

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
            $parts[] = e('%s logged', self::hours($hours));
        }

        if ($parts === []) {
            return $label.' · '.e('No activity');
        }

        $projects = $day['projects'];
        arsort($projects);
        $names = [];
        foreach (array_keys($projects) as $pid) {
            $names[] = $projectNames[$pid] ?? '#'.$pid;
        }

        return $label.' · '.implode(', ', $parts).($names === [] ? '' : ' · '.implode(', ', $names));
    }

    /**
     * One graph's year in a line, e.g. "42 events · 3.5 h logged in the last 12 months". Plain text.
     *
     * @param array{types:array<string,array{count:int,hours:float}>} $graph
     */
    public static function totalLine(array $graph): string
    {
        $events = 0;
        $hours = 0.0;
        foreach ($graph['types'] as $totals) {
            $events += (int) $totals['count'];
            $hours += (float) $totals['hours'];
        }
        $line = $events === 1 ? e('1 event') : e('%d events', $events);
        if ($hours > 0) {
            $line .= ' · '.e('%s logged', self::hours($hours));
        }

        return e('%s in the last 12 months', $line);
    }

    /**
     * Swatches for the type and project modes of one graph (the person mode is just the name).
     * Types in canonical order, unknown ones by name after them; projects by weight, with one
     * "Other" for every project that got no colour slot. Labels are plain text.
     *
     * @param array{types:array<string,array{count:int,hours:float}>, projects:array<int,float>} $graph
     * @param array<int,string> $projectColors
     * @param array<int,string> $projectNames
     * @return array{type:list<array{label:string,color:string}>, project:list<array{label:string,color:string}>}
     */
    public static function legend(array $graph, array $projectColors, array $projectNames): array
    {
        $types = array_keys(array_filter($graph['types'], fn ($t) => $t['count'] > 0 || $t['hours'] > 0));
        $types = array_map('strval', $types);
        usort($types, fn ($a, $b) => HeatmapBuilder::typeRank($a) <=> HeatmapBuilder::typeRank($b));

        $weights = $graph['projects'];
        $pids = array_keys($weights);
        usort($pids, fn ($a, $b) => [$weights[$b], $a] <=> [$weights[$a], $b]);
        $projects = [];
        $other = false;
        foreach ($pids as $pid) {
            $color = $projectColors[$pid] ?? Palette::OTHER;
            if ($color === Palette::OTHER) {
                $other = true;
                continue;
            }
            $projects[] = ['label' => (string) ($projectNames[$pid] ?? '#'.$pid), 'color' => $color];
        }
        if ($other) {
            $projects[] = ['label' => e('Other'), 'color' => Palette::OTHER];
        }

        return [
            'type' => array_map(fn ($type) => ['label' => self::typeLabel($type), 'color' => Palette::typeColour($type)], $types),
            'project' => $projects,
        ];
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'move' => e('Moves'),
            'task' => e('Tasks'),
            'comment' => e('Comments'),
            'subtask' => e('Subtasks'),
            'file' => e('Files'),
            'time' => e('Time logged'),
            default => $type,
        };
    }

    /** Hours for display: one decimal, ".0" dropped; "<0.1 h" when some time rounds away. */
    public static function hours(float $hours): string
    {
        $rounded = round($hours, 1);
        if ($hours > 0 && $rounded == 0) {
            return e('<0.1 h');
        }

        return e('%s h', $rounded == (int) $rounded ? (string) (int) $rounded : number_format($rounded, 1));
    }

    private static function countLabel(string $type, int $count): string
    {
        return match ($type) {
            'move' => $count === 1 ? e('1 move') : e('%d moves', $count),
            'task' => $count === 1 ? e('1 task event') : e('%d task events', $count),
            'comment' => $count === 1 ? e('1 comment') : e('%d comments', $count),
            'subtask' => $count === 1 ? e('1 subtask') : e('%d subtasks', $count),
            'file' => $count === 1 ? e('1 file') : e('%d files', $count),
            default => $count.' '.$type,
        };
    }
}
