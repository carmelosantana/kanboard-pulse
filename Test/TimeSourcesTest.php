<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Pulse\Model\Source\ManualTimeSource;
use Kanboard\Plugin\Pulse\Model\Source\TimerSource;

class TimeSourcesTest extends Base
{
    private TimerSource $timers;
    private ManualTimeSource $manual;
    private int $p1;
    private int $p2;
    private int $s1;
    private int $s2;
    private int $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $projects = new ProjectModel($this->container);
        $tasks = new TaskCreationModel($this->container);
        $subtasks = new SubtaskModel($this->container);
        $this->p1 = $projects->create(['name' => 'One']);
        $this->p2 = $projects->create(['name' => 'Two']);
        $t1 = $tasks->create(['project_id' => $this->p1, 'title' => 'A']);
        $t2 = $tasks->create(['project_id' => $this->p2, 'title' => 'B']);
        $this->s1 = $subtasks->create(['task_id' => $t1, 'title' => 'S1']);
        $this->s2 = $subtasks->create(['task_id' => $t2, 'title' => 'S2']);
        $this->u2 = (new UserModel($this->container))->create(['username' => 'bob']);
        $this->timers = new TimerSource($this->container);
        $this->manual = new ManualTimeSource($this->container);
    }

    /** TimeReport's version_3 DDL (TimeReport/Schema/Sqlite.php), copied so Pulse tests stand alone. */
    private function createManualTimeTable(): void
    {
        $pdo = $this->container['db']->getConnection();
        $pdo->exec("
            CREATE TABLE timereport_manual_time (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subtask_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL DEFAULT 0,
                hours REAL NOT NULL DEFAULT 0,
                entered_at INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY(subtask_id) REFERENCES subtasks(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec('CREATE INDEX timereport_manual_time_subtask_idx ON timereport_manual_time(subtask_id)');
        $pdo->exec('CREATE INDEX timereport_manual_time_at_idx ON timereport_manual_time(entered_at)');
    }

    private function timer(int $subtaskId, int $start, int $end, float $hours, int $user = 1): void
    {
        $this->container['db']->table('subtask_time_tracking')->insert([
            'subtask_id' => $subtaskId,
            'user_id' => $user,
            'start' => $start,
            'end' => $end,
            'time_spent' => $hours,
        ]);
    }

    private function manualEntry(int $subtaskId, int $enteredAt, float $hours, int $user = 1): void
    {
        $this->container['db']->table('timereport_manual_time')->insert([
            'subtask_id' => $subtaskId,
            'user_id' => $user,
            'hours' => $hours,
            'entered_at' => $enteredAt,
        ]);
    }

    public function testTypes(): void
    {
        $this->assertSame(['time'], $this->timers->types());
        $this->assertSame(['time'], $this->manual->types());
    }

    public function testTimerRowsDatedByEnd(): void
    {
        $this->timer($this->s1, 1000, 4600, 1.5);
        $this->timer($this->s1, 500, 900, 0.25, $this->u2);

        $rows = $this->timers->fetch([], [$this->p1], 0, 5000);

        $this->assertSame([
            ['user_id' => $this->u2, 'project_id' => $this->p1, 'ts' => 900, 'type' => 'time', 'count' => 0, 'hours' => 0.25],
            ['user_id' => 1, 'project_id' => $this->p1, 'ts' => 4600, 'type' => 'time', 'count' => 0, 'hours' => 1.5],
        ], $rows);

        // The range applies to end, inclusively, not to start.
        $this->assertCount(1, $this->timers->fetch([], [$this->p1], 1000, 4600));
        $this->assertSame([], $this->timers->fetch([], [$this->p1], 4601, 9000));
    }

    public function testOpenTimersIgnored(): void
    {
        $this->timer($this->s1, 1000, 0, 0.0);
        $this->assertSame([], $this->timers->fetch([], [$this->p1], 0, 2000000000));
    }

    public function testManualTimeWhenTableExists(): void
    {
        $this->createManualTimeTable();
        $this->manualEntry($this->s1, 3000, 2.0);
        $this->manualEntry($this->s1, 2000, 0.5, $this->u2);

        $this->assertSame([
            ['user_id' => $this->u2, 'project_id' => $this->p1, 'ts' => 2000, 'type' => 'time', 'count' => 0, 'hours' => 0.5],
            ['user_id' => 1, 'project_id' => $this->p1, 'ts' => 3000, 'type' => 'time', 'count' => 0, 'hours' => 2.0],
        ], $this->manual->fetch([], [$this->p1], 0, 5000));

        $this->assertCount(1, $this->manual->fetch([$this->u2], [$this->p1], 0, 5000));
        $this->assertCount(1, $this->manual->fetch([], [$this->p1], 2001, 3000));
    }

    public function testUndatedManualSkipped(): void
    {
        $this->createManualTimeTable();
        $this->manualEntry($this->s1, 0, 4.0);
        $this->assertSame([], $this->manual->fetch([], [$this->p1], 0, 2000000000));
    }

    public function testManualTimeTableMissingReturnsEmpty(): void
    {
        $this->assertSame([], $this->manual->fetch([], [$this->p1], 0, 2000000000));
    }

    public function testProjectFilterApplies(): void
    {
        $this->createManualTimeTable();
        $this->timer($this->s1, 100, 200, 1.0);
        $this->timer($this->s2, 100, 300, 2.0);
        $this->manualEntry($this->s1, 200, 1.0);
        $this->manualEntry($this->s2, 300, 2.0);

        foreach ([$this->timers, $this->manual] as $source) {
            $this->assertSame([$this->p2], array_column($source->fetch([], [$this->p2], 0, 1000), 'project_id'));
            $this->assertCount(2, $source->fetch([], [$this->p1, $this->p2], 0, 1000));
            $this->assertSame([], $source->fetch([], [], 0, 1000));
        }
    }

    public function testUserFilterApplies(): void
    {
        $this->timer($this->s1, 100, 200, 1.0);
        $this->timer($this->s1, 100, 300, 2.0, $this->u2);

        $rows = $this->timers->fetch([$this->u2], [$this->p1], 0, 1000);
        $this->assertSame([$this->u2], array_column($rows, 'user_id'));
    }
}
