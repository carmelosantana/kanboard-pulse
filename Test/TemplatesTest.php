<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Pulse\Model\PulseService;
use Kanboard\Plugin\Pulse\Plugin;

class TemplatesTest extends Base
{
    private int $alice;
    private int $bob;
    private int $pid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();
        $users = new UserModel($this->container);
        $this->alice = $users->create(['username' => 'alice']);
        $this->bob = $users->create(['username' => 'bob']);
        $this->pid = (new ProjectModel($this->container))->create(['name' => '<script>alert(1)</script>']);
        $roles = new ProjectUserRoleModel($this->container);
        $roles->addUser($this->pid, $this->alice, Role::PROJECT_MANAGER);
        $roles->addUser($this->pid, $this->bob, Role::PROJECT_MEMBER);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function render(string $template, array $params = []): string
    {
        return $this->container['template']->render($template, $params);
    }

    public function testGraphPartialEscapesTip(): void
    {
        $taskId = (new \Kanboard\Model\TaskCreationModel($this->container))->create(['project_id' => $this->pid, 'title' => 'Task']);
        $this->container['db']->table('project_activities')->remove();
        $this->container['db']->table('project_activities')->insert([
            'project_id' => $this->pid, 'task_id' => $taskId, 'creator_id' => $this->alice,
            'event_name' => 'task.move.column', 'date_creation' => time() - 60, 'data' => '{}',
        ]);
        $this->loginAs($this->alice);
        $data = (new PulseService($this->container))->userGraphs($this->alice, null, null);

        $html = $this->render('Pulse:pulse/graph', [
            'graph' => $data['graphs'][0],
            'user_colors' => $data['user_colors'],
            'project_colors' => $data['project_colors'],
            'project_names' => $data['projects'],
        ]);

        $this->assertStringContainsString('1 move ·', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('data-project-color="'.$data['project_colors'][$this->pid].'"', $html);
        $this->assertStringContainsString('data-type-color="#2a78d6"', $html);
        $this->assertStringContainsString('--pulse-c: '.$data['user_colors'][$this->alice], $html);
    }

    public function testDayTipFormatsBreakdown(): void
    {
        $day = [
            'n' => 9.5, 'level' => 4,
            'types' => [
                'comment' => ['count' => 3, 'hours' => 0.0],
                'time' => ['count' => 0, 'hours' => 1.5],
                'move' => ['count' => 4, 'hours' => 0.0],
                'task' => ['count' => 1, 'hours' => 0.0],
            ],
            'projects' => [7 => 2.0, 3 => 7.5],
        ];

        $tip = PulseService::dayTip('2026-11-03', $day, [3 => 'Website', 7 => 'Ops']);

        $this->assertSame('Nov 3, 2026 · 4 moves, 1 task event, 3 comments, 1.5 h logged · Website, Ops', $tip);
        $this->assertSame('Nov 4, 2026 · No activity', PulseService::dayTip('2026-11-04', ['types' => [], 'projects' => []], []));
        $this->assertSame('2 h', PulseService::hours(2.0));
        $this->assertSame('0.3 h', PulseService::hours(0.25));
    }

    public function testDayTipNeverCallsAColouredDayEmpty(): void
    {
        $day = ['types' => ['time' => ['count' => 0, 'hours' => 0.02]], 'projects' => [3 => 0.02]];

        $this->assertSame('Nov 3, 2026 · <0.1 h logged · Website', PulseService::dayTip('2026-11-03', $day, [3 => 'Website']));
    }

    public function testTotalLineSumsEventsAndHours(): void
    {
        $graph = fn (array $types) => ['total' => 1.0, 'types' => $types];

        $this->assertSame('3 events · 1.5 h logged in the last 12 months', PulseService::totalLine($graph([
            'move' => ['count' => 2, 'hours' => 0.0], 'time' => ['count' => 0, 'hours' => 1.5], 'comment' => ['count' => 1, 'hours' => 0.0],
        ])));
        $this->assertSame('1 event · 2 h logged in the last 12 months', PulseService::totalLine($graph([
            'move' => ['count' => 1, 'hours' => 0.0], 'time' => ['count' => 0, 'hours' => 2.0],
        ])));
        $this->assertSame('4 events in the last 12 months', PulseService::totalLine($graph(['task' => ['count' => 4, 'hours' => 0.0]])));
    }

    public function testLegendListsTypesInCanonicalOrderAndFoldsProjects(): void
    {
        $graph = [
            'types' => [
                'zap' => ['count' => 1, 'hours' => 0.0],
                'time' => ['count' => 0, 'hours' => 1.0],
                'commit' => ['count' => 2, 'hours' => 0.0],
                'comment' => ['count' => 1, 'hours' => 0.0],
                'move' => ['count' => 5, 'hours' => 0.0],
            ],
            'projects' => [7 => 2.0, 3 => 6.0, 9 => 1.0, 11 => 0.5],
        ];
        $colours = [3 => '#2a78d6', 7 => '#eb6834', 9 => '#898781', 11 => '#898781'];

        $legend = PulseService::legend($graph, $colours, [3 => 'Website', 7 => 'Ops']);

        $this->assertSame(
            [['Moves', '#2a78d6'], ['Comments', '#eb6834'], ['Time logged', '#6250d6'], ['commit', '#e87ba4'], ['zap', '#e87ba4']],
            array_map(fn ($i) => [$i['label'], $i['color']], $legend['type'])
        );
        $this->assertSame(
            [['Website', '#2a78d6'], ['Ops', '#eb6834'], ['Other', '#898781']],
            array_map(fn ($i) => [$i['label'], $i['color']], $legend['project'])
        );
    }

    public function testGraphPartialRendersALegendPerModeAndTheTotal(): void
    {
        $taskId = (new \Kanboard\Model\TaskCreationModel($this->container))->create(['project_id' => $this->pid, 'title' => 'Task']);
        $this->container['db']->table('project_activities')->remove();
        foreach ([60, 120] as $ago) {
            $this->container['db']->table('project_activities')->insert([
                'project_id' => $this->pid, 'task_id' => $taskId, 'creator_id' => $this->alice,
                'event_name' => 'comment.create', 'date_creation' => time() - $ago, 'data' => '{}',
            ]);
        }
        $this->loginAs($this->alice);
        $data = (new PulseService($this->container))->userGraphs($this->alice, null, null);

        $html = $this->render('Pulse:pulse/graph', [
            'graph' => $data['graphs'][0],
            'user_colors' => $data['user_colors'],
            'project_colors' => $data['project_colors'],
            'project_names' => $data['projects'],
        ]);

        $this->assertMatchesRegularExpression('/data-pulse-legend="person">[^<]*<span class="pulse-legend-item"><span class="pulse-swatch" style="background: '.preg_quote($data['user_colors'][$this->alice], '/').'"><\/span>alice<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-pulse-legend="type" hidden>\s*<span class="pulse-legend-item"><span class="pulse-swatch" style="background: #eb6834"><\/span>Comments<\/span>/', $html);
        $this->assertMatchesRegularExpression('/data-pulse-legend="project" hidden>\s*<span class="pulse-legend-item"><span class="pulse-swatch" style="background: [^"]+"><\/span>&lt;script&gt;alert\(1\)&lt;\/script&gt;<\/span>/', $html);
        $this->assertStringContainsString('<p class="pulse-total">2 events in the last 12 months</p>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testProjectMenuLinkHiddenForMember(): void
    {
        $project = $this->container['projectModel']->getById($this->pid);

        $this->loginAs($this->bob);
        $this->assertStringNotContainsString('Activity heatmap', $this->render('Pulse:project/dropdown', ['project' => $project]));

        $this->loginAs($this->alice);
        $this->assertStringContainsString('Activity heatmap', $this->render('Pulse:project/dropdown', ['project' => $project]));

        $this->loginAs(1);
        $this->assertStringContainsString('Activity heatmap', $this->render('Pulse:project/dropdown', ['project' => $project]));
    }

    public function testActivityLinksRenderOnHooks(): void
    {
        $this->loginAs($this->bob);
        $user = $this->container['userModel']->getById($this->bob);

        $this->assertStringContainsString('Activity', $this->container['helper']->hook->render('template:dashboard:sidebar', ['user' => $user]));
        $this->assertStringContainsString('Activity', $this->container['helper']->hook->render('template:header:dropdown'));
    }
}
