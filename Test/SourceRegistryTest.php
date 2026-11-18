<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
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

class SourceRegistryTest extends Base
{
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
