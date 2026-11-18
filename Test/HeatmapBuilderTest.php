<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Pulse\Model\HeatmapBuilder;

class HeatmapBuilderTest extends Base
{
    private \DateTimeZone $ny;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ny = new \DateTimeZone('America/New_York');
    }

    private function today(string $date = '2026-11-18 15:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, $this->ny);
    }

    private function row(string $local, string $type = 'task', int $count = 1, float $hours = 0.0, int $project = 1, int $user = 1): array
    {
        $ts = (new \DateTimeImmutable($local, $this->ny))->getTimestamp();
        return ['user_id' => $user, 'project_id' => $project, 'ts' => $ts, 'type' => $type, 'count' => $count, 'hours' => $hours];
    }

    private function build(array $rows, ?\DateTimeImmutable $today = null): array
    {
        return HeatmapBuilder::build($rows, 1, $this->ny, $today ?? $this->today());
    }

    public function testRangeStartsOnSundayAndEndsToday(): void
    {
        $range = HeatmapBuilder::range($this->today());

        // 2026-11-18 is a Wednesday; its week's Sunday is 2026-11-15; minus 52 weeks = 2025-11-16.
        $this->assertSame('2025-11-16', $range['start']);
        $this->assertSame((new \DateTimeImmutable('2025-11-16 00:00:00', $this->ny))->getTimestamp(), $range['from']);
        $this->assertSame((new \DateTimeImmutable('2026-11-18 23:59:59', $this->ny))->getTimestamp(), $range['to']);
        $this->assertSame('0', (new \DateTimeImmutable($range['start']))->format('w'));

        // On a Sunday, the range starts exactly 52 weeks earlier.
        $this->assertSame('2025-11-16', HeatmapBuilder::range($this->today('2026-11-15 08:00'))['start']);

        $graph = $this->build([]);
        $this->assertSame('2025-11-16', $graph['start']);
        $this->assertSame(53, $graph['weeks']);
        $this->assertSame('2026-11-18', array_key_last($graph['days']));
        $this->assertSame(53, HeatmapBuilder::build([], 1, $this->ny, $this->today('2026-11-21 23:00'))['weeks']);
    }

    public function testLocalDayBucketing(): void
    {
        $ts = (new \DateTimeImmutable('2026-06-12 02:30:00', new \DateTimeZone('UTC')))->getTimestamp();
        $graph = $this->build([['user_id' => 1, 'project_id' => 1, 'ts' => $ts, 'type' => 'task', 'count' => 1, 'hours' => 0.0]]);

        $this->assertSame(1.0, $graph['days']['2026-06-11']['n']);
        $this->assertSame(0.0, $graph['days']['2026-06-12']['n']);
    }

    public function testDstWeekStillContiguous(): void
    {
        $graph = $this->build([
            $this->row('2026-03-08 12:00'),
            $this->row('2026-11-01 12:00'),
        ]);
        $keys = array_keys($graph['days']);

        $expected = [];
        $d = new \DateTimeImmutable('2025-11-16', new \DateTimeZone('UTC'));
        while ($d->format('Y-m-d') <= '2026-11-18') {
            $expected[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        $this->assertSame($expected, $keys);
        $this->assertSame(1.0, $graph['days']['2026-03-08']['n']);
        $this->assertSame(1.0, $graph['days']['2026-11-01']['n']);
    }

    public function testHoursCountAsEvents(): void
    {
        $graph = $this->build([
            $this->row('2026-11-10 09:00', 'time', 0, 2.5),
            $this->row('2026-11-10 10:00', 'comment', 2),
            $this->row('2026-11-10 11:00', 'task', 3, 0.0, 1, 2), // other user: ignored
        ]);
        $day = $graph['days']['2026-11-10'];

        $this->assertSame(1, $graph['user_id']);
        $this->assertSame(4.5, $day['n']);
        $this->assertSame(4.5, $graph['total']);
        $this->assertSame(['count' => 0, 'hours' => 2.5], $day['types']['time']);
        $this->assertSame(['count' => 2, 'hours' => 0.0], $day['types']['comment']);
        $this->assertArrayNotHasKey('task', $day['types']);
        $this->assertSame([1 => 4.5], $day['projects']);
    }

    public function testGraphTotalsByTypeAndProject(): void
    {
        $graph = $this->build([
            $this->row('2026-11-10 09:00', 'time', 0, 2.5, 3),
            $this->row('2026-11-11 10:00', 'comment', 2, 0.0, 1),
            $this->row('2026-11-12 10:00', 'comment', 1, 0.0, 3),
            $this->row('2026-11-12 11:00', 'task', 3, 0.0, 1, 2), // other user: ignored
        ]);

        $this->assertSame(['time' => ['count' => 0, 'hours' => 2.5], 'comment' => ['count' => 3, 'hours' => 0.0]], $graph['types']);
        $this->assertSame([3 => 3.5, 1 => 2.0], $graph['projects']);
    }

    public function testTopTypeAndProjectWithTies(): void
    {
        $graph = $this->build([
            $this->row('2026-11-10 09:00', 'commit', 2, 0.0, 7),
            $this->row('2026-11-10 09:00', 'file', 2, 0.0, 5),
            $this->row('2026-11-10 09:00', 'comment', 2, 0.0, 9),
            // a day where only an unknown type and a known type tie
            $this->row('2026-11-11 09:00', 'commit', 1, 0.0, 3),
            $this->row('2026-11-11 09:00', 'time', 0, 1.0, 3),
            // unknown type wins when it is strictly larger
            $this->row('2026-11-12 09:00', 'commit', 5, 0.0, 4),
            $this->row('2026-11-12 09:00', 'move', 1, 0.0, 2),
        ]);

        $this->assertSame('comment', $graph['days']['2026-11-10']['top_type']);
        $this->assertSame(5, $graph['days']['2026-11-10']['top_project']);
        $this->assertSame('time', $graph['days']['2026-11-11']['top_type']);
        $this->assertSame(3, $graph['days']['2026-11-11']['top_project']);
        $this->assertSame('commit', $graph['days']['2026-11-12']['top_type']);
        $this->assertSame(4, $graph['days']['2026-11-12']['top_project']);
        $this->assertNull($graph['days']['2026-11-13']['top_type']);
        $this->assertNull($graph['days']['2026-11-13']['top_project']);
    }

    public function testQuartileLevels(): void
    {
        $rows = [];
        for ($n = 1; $n <= 8; $n++) {
            $rows[] = $this->row(sprintf('2026-11-%02d 12:00', $n), 'task', $n);
        }
        $graph = $this->build($rows);

        $levels = [];
        for ($n = 1; $n <= 8; $n++) {
            $levels[] = $graph['days'][sprintf('2026-11-%02d', $n)]['level'];
        }
        $this->assertSame([1, 1, 2, 2, 3, 3, 4, 4], $levels);
        $this->assertSame(0, $graph['days']['2026-11-09']['level']);
    }

    public function testEmptyGraph(): void
    {
        $graph = $this->build([$this->row('2026-11-10 09:00', 'task', 4, 0.0, 1, 2)]);

        $this->assertSame(0.0, $graph['total']);
        $this->assertSame([], $graph['types']);
        $this->assertSame([], $graph['projects']);
        $this->assertCount(52 * 7 + 4, $graph['days']);
        foreach ($graph['days'] as $day) {
            $this->assertSame(0.0, $day['n']);
            $this->assertSame(0, $day['level']);
            $this->assertSame([], $day['types']);
            $this->assertSame([], $day['projects']);
        }
    }
}
