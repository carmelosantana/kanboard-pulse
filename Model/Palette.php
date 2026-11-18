<?php

namespace Kanboard\Plugin\Pulse\Model;

/**
 * Stable colours: per-series (user or project) slots and per-activity-type colours.
 */
class Palette
{
    public const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    public const OTHER = '#898781';
    public const TYPES = [
        'move' => '#2a78d6',
        'task' => '#008300',
        'comment' => '#eb6834',
        'subtask' => '#1baf7a',
        'file' => '#eda100',
        'time' => '#6250d6',
    ];
    public const UNKNOWN_TYPE = '#e87ba4';

    public static function typeColour(string $type): string
    {
        return self::TYPES[$type] ?? self::UNKNOWN_TYPE;
    }

    /**
     * The 8 heaviest ids get a series colour (preferred slot `id % 8`, linear probe to the
     * next free slot); the rest get OTHER. Ties go to the lower id.
     *
     * @param array<int, int|float> $weights id => weight
     * @return array<int, string> id => colour
     */
    public static function assign(array $weights): array
    {
        $ids = array_keys($weights);
        usort($ids, fn ($a, $b) => [$weights[$b], $a] <=> [$weights[$a], $b]);

        $slots = count(self::SERIES);
        $taken = [];
        $colours = [];
        foreach ($ids as $rank => $id) {
            if ($rank >= $slots) {
                $colours[$id] = self::OTHER;
                continue;
            }
            $slot = $id % $slots;
            while (isset($taken[$slot])) {
                $slot = ($slot + 1) % $slots;
            }
            $taken[$slot] = true;
            $colours[$id] = self::SERIES[$slot];
        }

        return $colours;
    }
}
