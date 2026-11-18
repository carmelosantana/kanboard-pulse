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
    public function types(): array
    {
        return ['commit'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        return [['user_id' => 1, 'project_id' => 1, 'ts' => $from, 'type' => 'commit', 'count' => 1, 'hours' => 0.0]];
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

        // Built-ins return [] for an empty project list, so only the fake's row comes back.
        $this->assertSame(
            [['user_id' => 1, 'project_id' => 1, 'ts' => 100, 'type' => 'commit', 'count' => 1, 'hours' => 0.0]],
            $registry->fetchAll([], [], 100, 200)
        );
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
