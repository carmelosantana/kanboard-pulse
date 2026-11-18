<?php

namespace Kanboard\Plugin\Pulse\Model;

/**
 * Turns flat activity rows into one person's 12-month grid of local days with shade levels.
 *
 * Pure logic: no database, no container.
 */
class HeatmapBuilder
{
    /** Tie-break order for a day's dominant type; unknown types sort after these, alphabetically. */
    private const TYPE_ORDER = ['move', 'task', 'comment', 'subtask', 'file', 'time'];

    /**
     * The window shown: from the Sunday 52 weeks before this week's Sunday to the end of today,
     * in $today's timezone.
     *
     * @return array{from:int, to:int, start:string}
     */
    public static function range(\DateTimeImmutable $today): array
    {
        $midnight = $today->setTime(0, 0);
        $start = $midnight->modify('-'.$midnight->format('w').' days')->modify('-52 weeks');

        return [
            'from' => $start->getTimestamp(),
            'to' => $today->setTime(23, 59, 59)->getTimestamp(),
            'start' => $start->format('Y-m-d'),
        ];
    }

    /**
     * @param list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}> $rows
     */
    public static function build(array $rows, int $userId, \DateTimeZone $tz, \DateTimeImmutable $today): array
    {
        $today = $today->setTimezone($tz);
        $start = self::range($today)['start'];
        $days = self::emptyDays($start, $today->format('Y-m-d'));
        $total = 0.0;

        foreach ($rows as $row) {
            if ((int) $row['user_id'] !== $userId) {
                continue;
            }
            $key = (new \DateTimeImmutable('@'.$row['ts']))->setTimezone($tz)->format('Y-m-d');
            if (! isset($days[$key])) {
                continue;
            }
            $n = (int) $row['count'] + (float) $row['hours'];
            $type = $row['type'];
            $pid = (int) $row['project_id'];
            $day = &$days[$key];
            $day['n'] += $n;
            $day['types'][$type] ??= ['count' => 0, 'hours' => 0.0];
            $day['types'][$type]['count'] += (int) $row['count'];
            $day['types'][$type]['hours'] += (float) $row['hours'];
            $day['projects'][$pid] = ($day['projects'][$pid] ?? 0.0) + $n;
            $total += $n;
            unset($day);
        }

        $thresholds = self::thresholds($days);
        foreach ($days as &$day) {
            $day['level'] = self::level($day['n'], $thresholds);
            $day['top_type'] = self::topType($day['types']);
            $day['top_project'] = self::topProject($day['projects']);
        }
        unset($day);

        return [
            'user_id' => $userId,
            'start' => $start,
            'weeks' => intdiv(count($days) + 6, 7),
            'total' => $total,
            'days' => $days,
        ];
    }

    /** Every calendar date from $start to $end inclusive, stepped in UTC so DST never skips or repeats a day. */
    private static function emptyDays(string $start, string $end): array
    {
        $days = [];
        $date = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        while (($key = $date->format('Y-m-d')) <= $end) {
            $days[$key] = ['n' => 0.0, 'level' => 0, 'types' => [], 'projects' => [], 'top_type' => null, 'top_project' => null];
            $date = $date->modify('+1 day');
        }
        return $days;
    }

    /** 25th/50th/75th nearest-rank percentiles of the non-zero day totals. */
    private static function thresholds(array $days): array
    {
        $values = array_values(array_filter(array_column($days, 'n'), fn ($n) => $n > 0));
        if ($values === []) {
            return [];
        }
        sort($values);
        $count = count($values);
        return array_map(fn ($p) => $values[(int) ceil($p * $count) - 1], [0.25, 0.5, 0.75]);
    }

    private static function level(float $n, array $thresholds): int
    {
        if ($n <= 0) {
            return 0;
        }
        $below = count(array_filter($thresholds, fn ($t) => $t < $n));
        return min(4, 1 + $below);
    }

    private static function topType(array $types): ?string
    {
        $best = null;
        $bestN = 0.0;
        foreach ($types as $type => $totals) {
            $n = $totals['count'] + $totals['hours'];
            if ($best === null || $n > $bestN || ($n == $bestN && self::typeRank($type) < self::typeRank($best))) {
                $best = $type;
                $bestN = $n;
            }
        }
        return $best === null ? null : (string) $best;
    }

    /** Known types by position, unknown ones after them in alphabetical order. */
    private static function typeRank(string $type): array
    {
        $pos = array_search($type, self::TYPE_ORDER, true);
        return $pos === false ? [count(self::TYPE_ORDER), $type] : [$pos, ''];
    }

    private static function topProject(array $projects): ?int
    {
        $best = null;
        foreach ($projects as $pid => $n) {
            if ($best === null || $n > $projects[$best] || ($n == $projects[$best] && $pid < $best)) {
                $best = $pid;
            }
        }
        return $best;
    }
}
