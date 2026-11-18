<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Plugin\Pulse\Model\ActivitySource;
use Kanboard\Plugin\Pulse\Model\Source\ManualTimeSource;
use Kanboard\Plugin\Pulse\Model\Source\ProjectActivitySource;
use Kanboard\Plugin\Pulse\Model\Source\TimerSource;
use Kanboard\Plugin\Pulse\Model\SourceRegistry;

class SourceRegistryFakeSource implements ActivitySource
{
    /** @param list<array>|null $rows fixed rows; null returns one row for user 1 in the first requested project */
    public function __construct(private ?array $rows = null)
    {
    }

    public function types(): array
    {
        return ['commit'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        return $this->rows ?? [['user_id' => 1, 'project_id' => $projectIds[0] ?? 1, 'ts' => $from, 'type' => 'commit', 'count' => 1, 'hours' => 0.0]];
    }
}

class SourceRegistryFailingSource implements ActivitySource
{
    public function types(): array
    {
        return ['commit'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        throw new \RuntimeException('git host unreachable');
    }
}

/** A registry whose only built-in is the given source, to check built-in rows pass the same gate. */
class SourceRegistryWithBuiltin extends SourceRegistry
{
    public ActivitySource $builtin;

    protected function builtins(): array
    {
        return [$this->builtin];
    }
}

class SourceRegistryTest extends Base
{
    /** One project with a single task.create activity at ts 1000; returns the project id. */
    private function projectWithOneActivity(): int
    {
        $projectId = (new ProjectModel($this->container))->create(['name' => 'One']);
        $taskId = (new TaskCreationModel($this->container))->create(['project_id' => $projectId, 'title' => 'A']);
        $this->container['db']->table('project_activities')->remove();
        $this->container['db']->table('project_activities')->insert([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'creator_id' => 1,
            'event_name' => 'task.create',
            'date_creation' => 1000,
            'data' => '{}',
        ]);

        return $projectId;
    }

    private function expectOneErrorLog(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Pulse'));
        $this->container['logger'] = $logger;
    }

    public function testRegistrySkipsListenerThatThrows()
    {
        $projectId = $this->projectWithOneActivity();
        $this->expectOneErrorLog();
        $this->container['hook']->on('pulse:sources', function () {
            throw new \RuntimeException('broken plugin');
        });

        $registry = new SourceRegistry($this->container);
        $rows = $registry->fetchAll([], [$projectId], 0, 2000);

        $this->assertSame(['task'], array_column($rows, 'type'));
    }

    public function testFetchAllDropsRowsOfThirdPartySourceThatThrows()
    {
        $projectId = $this->projectWithOneActivity();
        $this->expectOneErrorLog();
        $this->container['hook']->on('pulse:sources', fn () => new SourceRegistryFailingSource);

        $rows = (new SourceRegistry($this->container))->fetchAll([], [$projectId], 0, 2000);

        $this->assertSame(['task'], array_column($rows, 'type'));
    }

    public function testRegistryIncludesBuiltinsAndHookSources()
    {
        $this->container['hook']->on('pulse:sources', fn () => new SourceRegistryFakeSource);

        $registry = new SourceRegistry($this->container);
        $sources = $registry->all();

        $this->assertCount(4, $sources);
        $this->assertInstanceOf(ProjectActivitySource::class, $sources[0]);
        $this->assertInstanceOf(TimerSource::class, $sources[1]);
        $this->assertInstanceOf(ManualTimeSource::class, $sources[2]);
        $this->assertInstanceOf(SourceRegistryFakeSource::class, $sources[3]);

        $projectId = $this->projectWithOneActivity();
        $this->assertSame(
            [
                ['user_id' => 1, 'project_id' => $projectId, 'ts' => 1000, 'type' => 'task', 'count' => 1, 'hours' => 0.0],
                ['user_id' => 1, 'project_id' => $projectId, 'ts' => 0, 'type' => 'commit', 'count' => 1, 'hours' => 0.0],
            ],
            $registry->fetchAll([], [$projectId], 0, 2000)
        );
    }

    public function testFetchAllWithNoProjectsReturnsNothing()
    {
        $this->projectWithOneActivity();
        $this->container['hook']->on('pulse:sources', fn () => new SourceRegistryFakeSource);

        $this->assertSame([], (new SourceRegistry($this->container))->fetchAll([], [], 0, 2000));
    }

    public function testFetchAllDropsOutOfScopeThirdPartyRows()
    {
        $projectId = $this->projectWithOneActivity();
        $row = fn (int $user, int $project) => ['user_id' => $user, 'project_id' => $project, 'ts' => 1500, 'type' => 'commit', 'count' => 1, 'hours' => 0.0];
        $this->container['hook']->on('pulse:sources', fn () => new SourceRegistryFakeSource([
            $row(1, $projectId),
            $row(1, $projectId + 999), // project the viewer did not ask for
            $row(2, $projectId),       // user outside the requested set
        ]));
        $registry = new SourceRegistry($this->container);

        $this->assertSame(
            [[1, $projectId, 'task'], [1, $projectId, 'commit']],
            array_map(fn ($r) => [$r['user_id'], $r['project_id'], $r['type']], $registry->fetchAll([1], [$projectId], 0, 2000))
        );
        // No user filter: the other user's row stays, the foreign project's row still goes.
        $this->assertSame(
            [[1, $projectId, 'task'], [1, $projectId, 'commit'], [2, $projectId, 'commit']],
            array_map(fn ($r) => [$r['user_id'], $r['project_id'], $r['type']], $registry->fetchAll([], [$projectId], 0, 2000))
        );
    }

    public function testFetchAllDropsMalformedThirdPartyRowsAndNormalisesTheRest()
    {
        $projectId = $this->projectWithOneActivity();
        $this->container['hook']->on('pulse:sources', fn () => new SourceRegistryFakeSource([
            ['user_id' => 1, 'project_id' => $projectId, 'type' => 'commit', 'count' => 1, 'hours' => 0.0],                  // no ts
            ['user_id' => 1, 'project_id' => $projectId, 'ts' => 1500, 'type' => ['commit'], 'count' => 1, 'hours' => 0.0], // array type
            ['user_id' => 1, 'project_id' => $projectId, 'ts' => 1500, 'type' => '', 'count' => 1, 'hours' => 0.0],         // empty type
            ['user_id' => 0, 'project_id' => $projectId, 'ts' => 1500, 'type' => 'commit', 'count' => 1, 'hours' => 0.0],   // no user
            ['user_id' => 1, 'project_id' => $projectId, 'ts' => 2500, 'type' => 'commit', 'count' => 1, 'hours' => 0.0],   // after range
            ['user_id' => '5', 'project_id' => (string) $projectId, 'ts' => '1500', 'type' => 'commit', 'count' => '2', 'hours' => '0.5'],
        ]));

        $rows = (new SourceRegistry($this->container))->fetchAll([], [$projectId], 0, 2000);

        $this->assertSame(
            ['user_id' => 5, 'project_id' => $projectId, 'ts' => 1500, 'type' => 'commit', 'count' => 2, 'hours' => 0.5],
            $rows[1]
        );
        $this->assertCount(2, $rows);
    }

    public function testFetchAllDropsOutOfScopeBuiltinRows()
    {
        $row = fn (int $user, int $project, int $ts) => ['user_id' => $user, 'project_id' => $project, 'ts' => $ts, 'type' => 'task', 'count' => 1, 'hours' => 0.0];
        $registry = new SourceRegistryWithBuiltin($this->container);
        $registry->builtin = new SourceRegistryFakeSource([
            $row(1, 3, 1500),
            $row(1, 4, 1500),  // project not requested
            $row(2, 3, 1500),  // user not requested
            $row(1, 3, 999),   // before range
            $row(1, 3, 2001),  // after range
        ]);

        $this->assertSame([$row(1, 3, 1500)], $registry->fetchAll([1], [3], 1000, 2000));
    }

    public function testRegistrySkipsNonSources()
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with($this->stringContains('Pulse'));
        $this->container['logger'] = $logger;
        $this->container['hook']->on('pulse:sources', fn () => 'not a source');

        $sources = (new SourceRegistry($this->container))->all();

        $this->assertCount(3, $sources);
        foreach ($sources as $source) {
            $this->assertInstanceOf(ActivitySource::class, $source);
        }
    }
}
