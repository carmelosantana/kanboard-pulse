<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Pulse\Controller\PulseController;
use Kanboard\Plugin\Pulse\Plugin;

class PulseControllerTest extends Base
{
    private int $alice;
    private int $bob;
    private int $p1;
    private int $p2;
    private int $t1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container['logger'] = $this->createMock(\Psr\Log\LoggerInterface::class);
        (new Plugin($this->container))->initialize();

        $users = new UserModel($this->container);
        $this->alice = $users->create(['username' => 'alice', 'name' => 'Alice Example']);
        $this->bob = $users->create(['username' => 'bob', 'name' => 'Bob Example']);
        $projects = new ProjectModel($this->container);
        $this->p1 = $projects->create(['name' => 'Website']);
        $this->p2 = $projects->create(['name' => 'Ops']);
        $roles = new ProjectUserRoleModel($this->container);
        $roles->addUser($this->p1, $this->alice, Role::PROJECT_MANAGER);
        $roles->addUser($this->p1, $this->bob, Role::PROJECT_MEMBER);
        $roles->addUser($this->p2, $this->bob, Role::PROJECT_MEMBER);
        $this->t1 = (new TaskCreationModel($this->container))->create(['project_id' => $this->p1, 'title' => 'A']);
        $this->container['db']->table('project_activities')->remove();
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
        $this->container['memoryCache']->flush();
    }

    private function event(int $userId, int $projectId, string $name = 'comment.create', ?int $ts = null): void
    {
        $this->container['db']->table('project_activities')->insert([
            'project_id' => $projectId,
            'task_id' => $this->t1,
            'creator_id' => $userId,
            'event_name' => $name,
            'date_creation' => $ts ?? time() - 3600,
            'data' => '{}',
        ]);
    }

    private function call(string $action, array $get): string
    {
        $this->container['request'] = new Request($this->container, ['REQUEST_METHOD' => 'GET'], $get ?: ['_' => '1'], [], [], []);
        $html = '';
        $resp = $this->createMock(Response::class);
        $resp->method('html')->willReturnCallback(function ($out) use (&$html) { $html = (string) $out; });
        $this->container['response'] = $resp;
        (new PulseController($this->container))->$action();
        return $html;
    }

    public function testIndexRendersOwnGraph(): void
    {
        $this->event($this->alice, $this->p1);
        $this->event($this->alice, $this->p1);
        $this->loginAs($this->alice);

        $html = $this->call('index', []);

        $this->assertStringContainsString('data-user-id="'.$this->alice.'"', $html);
        $this->assertStringContainsString('Alice Example', $html);
        $this->assertStringContainsString('2 comments', $html);
        $this->assertStringContainsString('data-pulse-mode="person"', $html);
        $this->assertStringNotContainsString('No activity in the last 12 months', $html);
        $this->assertStringNotContainsString('name="user_id"', $html, 'the person picker is admin-only');
        $this->assertSame(1, substr_count($html, 'class="pulse-graph"'));
    }

    public function testIndexShowsEmptyLineWithoutActivity(): void
    {
        $this->loginAs($this->bob);

        $html = $this->call('index', []);

        $this->assertStringContainsString('No activity in the last 12 months', $html);
        $this->assertStringContainsString('<select name="project_id"', $html);
    }

    public function testIndexForbidsForeignUserForNonAdmin(): void
    {
        $this->loginAs($this->alice);
        $this->expectException(AccessForbiddenException::class);
        $this->call('index', ['user_id' => $this->bob]);
    }

    public function testAdminPicksAnotherPerson(): void
    {
        $this->event($this->bob, $this->p2);
        $this->loginAs(1);

        $html = $this->call('index', ['user_id' => $this->bob]);

        $this->assertStringContainsString('name="user_id"', $html);
        $this->assertStringContainsString('data-user-id="'.$this->bob.'"', $html);
        $this->assertStringContainsString('1 comment', $html);
    }

    public function testProjectFilterLimitsActivity(): void
    {
        $this->event($this->bob, $this->p1);
        $this->event($this->bob, $this->p2);
        $this->loginAs($this->bob);

        $html = $this->call('index', ['project_id' => $this->p2]);

        $this->assertStringContainsString('1 comment ·', $html);
        $this->assertStringNotContainsString('2 comments', $html);
    }

    public function testProjectPageForbiddenForMember(): void
    {
        $this->loginAs($this->bob);
        $this->expectException(AccessForbiddenException::class);
        $this->call('project', ['project_id' => $this->p1]);
    }

    public function testProjectPageListsActiveUsersOnly(): void
    {
        $this->event($this->alice, $this->p1);
        $this->event(1, $this->p1);
        $this->event(1, $this->p1);
        $this->event($this->bob, $this->p2); // bob is active, but not in this project
        $this->loginAs($this->alice);

        $html = $this->call('project', ['project_id' => $this->p1]);

        $this->assertStringContainsString('data-user-id="1"', $html, 'a non-member admin with activity is listed');
        $this->assertStringContainsString('data-user-id="'.$this->alice.'"', $html);
        $this->assertStringNotContainsString('data-user-id="'.$this->bob.'"', $html, 'a member without activity here is not');
        $this->assertLessThan(
            strpos($html, 'data-user-id="'.$this->alice.'"'),
            strpos($html, 'data-user-id="1"'),
            'graphs are ordered by activity'
        );
    }
}
