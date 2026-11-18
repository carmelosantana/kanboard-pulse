<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Pulse\Model\Source\ProjectActivitySource;

class ProjectActivitySourceTest extends Base
{
    private ProjectActivitySource $source;
    private int $p1;
    private int $p2;
    private int $t1;
    private int $t2;
    private int $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $projects = new ProjectModel($this->container);
        $tasks = new TaskCreationModel($this->container);
        $this->p1 = $projects->create(['name' => 'One']);
        $this->p2 = $projects->create(['name' => 'Two']);
        $this->t1 = $tasks->create(['project_id' => $this->p1, 'title' => 'A']);
        $this->t2 = $tasks->create(['project_id' => $this->p2, 'title' => 'B']);
        $this->u2 = (new UserModel($this->container))->create(['username' => 'bob']);
        $this->container['db']->table('project_activities')->remove();
        $this->source = new ProjectActivitySource($this->container);
    }

    private function event(string $name, int $ts, array|string $data = [], int $user = 1, ?int $project = null, ?int $task = null): void
    {
        $this->container['db']->table('project_activities')->insert([
            'project_id' => $project ?? $this->p1,
            'task_id' => $task ?? $this->t1,
            'creator_id' => $user,
            'event_name' => $name,
            'date_creation' => $ts,
            'data' => is_string($data) ? $data : json_encode($data),
        ]);
    }

    private function subtaskData(int $id, int $status, string $title = 'S'): array
    {
        return ['subtask' => ['id' => $id, 'status' => $status, 'title' => $title], 'changes' => [], 'task' => []];
    }

    private function fetch(array $users = [], ?array $projects = null, int $from = 0, int $to = 2000000000): array
    {
        return $this->source->fetch($users, $projects ?? [$this->p1], $from, $to);
    }

    private function typesOf(array $rows): array
    {
        $types = array_column($rows, 'type');
        sort($types);
        return $types;
    }

    public function testTypes(): void
    {
        $this->assertSame(['move', 'task', 'comment', 'subtask', 'file'], $this->source->types());
    }

    public function testMapsEventNamesToTypes(): void
    {
        $this->event('task.move.column', 1000);
        $this->event('task.create', 1010);
        $this->event('comment.create', 1020);
        $this->event('subtask.create', 1030);
        $this->event('task.file.create', 1040);
        $this->event('task.update', 1050);
        $this->event('comment.user.mention', 1060);

        $rows = $this->fetch();
        $this->assertSame(['comment', 'file', 'move', 'subtask', 'task'], $this->typesOf($rows));
        foreach ($rows as $row) {
            $this->assertSame(1, $row['user_id']);
            $this->assertSame($this->p1, $row['project_id']);
            $this->assertSame(1, $row['count']);
            $this->assertSame(0.0, $row['hours']);
        }
        $byType = array_column($rows, 'ts', 'type');
        $this->assertSame(1000, $byType['move']);
        $this->assertSame(1040, $byType['file']);
    }

    public function testMapsEveryListedEventName(): void
    {
        foreach (['task.move.column', 'task.move.position', 'task.move.swimlane', 'task.move.project'] as $i => $name) {
            $this->event($name, 1000 + $i * 10);
        }
        foreach (['task.create', 'task.close', 'task.open'] as $i => $name) {
            $this->event($name, 2000 + $i * 10);
        }
        $this->assertSame(['move', 'move', 'move', 'move', 'task', 'task', 'task'], $this->typesOf($this->fetch()));
    }

    public function testDedupesMultiRowDrag(): void
    {
        $this->event('task.move.swimlane', 1000);
        $this->event('task.move.column', 1000);
        $this->assertCount(1, $this->fetch());

        $this->event('task.move.swimlane', 2000);
        $this->event('task.move.column', 2001);
        $this->assertSame(['move', 'move', 'move'], $this->typesOf($this->fetch()));
    }

    public function testDragDedupeIsPerUserAndTask(): void
    {
        $this->event('task.move.column', 1000);
        $this->event('task.move.column', 1000, [], $this->u2);
        $this->event('task.move.column', 1000, [], 1, $this->p2, $this->t2);
        $this->assertCount(3, $this->source->fetch([], [$this->p1, $this->p2], 0, 2000));
    }

    public function testSubtaskCompletionCountsOnce(): void
    {
        $this->event('subtask.update', 1000, $this->subtaskData(7, 1));
        $this->event('subtask.update', 1100, $this->subtaskData(7, SubtaskModel::STATUS_DONE));
        $this->event('subtask.update', 1200, $this->subtaskData(7, SubtaskModel::STATUS_DONE, 'Renamed'));
        $this->event('subtask.update', 1300, $this->subtaskData(7, 0));
        $this->event('subtask.update', 1400, $this->subtaskData(7, SubtaskModel::STATUS_DONE));

        $rows = $this->fetch();
        $this->assertCount(1, $rows);
        $this->assertSame('subtask', $rows[0]['type']);
        $this->assertSame(1100, $rows[0]['ts']);
    }

    public function testCompletionsArePerSubtask(): void
    {
        $this->event('subtask.update', 1000, $this->subtaskData(7, SubtaskModel::STATUS_DONE));
        $this->event('subtask.update', 1100, $this->subtaskData(8, SubtaskModel::STATUS_DONE));
        $this->assertSame([1000, 1100], array_column($this->fetch(), 'ts'));
    }

    public function testCompletionBeforeRangeNotRecounted(): void
    {
        $this->event('subtask.update', 500, $this->subtaskData(7, SubtaskModel::STATUS_DONE));
        $this->event('subtask.update', 1500, $this->subtaskData(7, SubtaskModel::STATUS_DONE, 'Renamed'));
        $this->assertSame([], $this->fetch([], null, 1000, 2000));
    }

    public function testDecodesSerializedAndJson(): void
    {
        $this->event('subtask.update', 1000, serialize($this->subtaskData(7, SubtaskModel::STATUS_DONE)));
        $this->event('subtask.update', 1100, $this->subtaskData(8, SubtaskModel::STATUS_DONE));
        $this->assertSame([1000, 1100], array_column($this->fetch(), 'ts'));
    }

    public function testSkipsGarbageData(): void
    {
        $this->event('subtask.update', 1000, 'not json or serialized {');
        $this->event('subtask.update', 1050, 'O:8:"stdClass":0:{}');
        $this->event('subtask.update', 1100, $this->subtaskData(7, SubtaskModel::STATUS_DONE));
        $this->assertSame([1100], array_column($this->fetch(), 'ts'));
    }

    public function testFiltersByUsersProjectsAndRange(): void
    {
        $this->event('task.create', 1000);
        $this->event('task.create', 1000, [], $this->u2);
        $this->event('task.create', 1000, [], 1, $this->p2, $this->t2);
        $this->event('task.create', 999);
        $this->event('task.create', 2001);
        $this->event('task.create', 2000);
        $this->event('subtask.update', 1500, $this->subtaskData(7, SubtaskModel::STATUS_DONE), $this->u2);

        $rows = $this->source->fetch([1], [$this->p1], 1000, 2000);
        $this->assertSame([1000, 2000], array_column($rows, 'ts'));

        $any = $this->source->fetch([], [$this->p1], 1000, 2000);
        $this->assertCount(4, $any);

        $this->assertCount(5, $this->source->fetch([], [$this->p1, $this->p2], 1000, 2000));
        $this->assertSame([], $this->source->fetch([], [], 0, 2000000000));
    }
}
