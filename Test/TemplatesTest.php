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
