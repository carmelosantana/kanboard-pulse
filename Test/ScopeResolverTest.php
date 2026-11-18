<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Model\GroupMemberModel;
use Kanboard\Model\GroupModel;
use Kanboard\Model\ProjectGroupRoleModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\ProjectUserRoleModel;
use Kanboard\Model\UserModel;
use Kanboard\Plugin\Pulse\Model\ScopeResolver;

class ScopeResolverTest extends Base
{
    private ScopeResolver $scope;
    private ProjectModel $projects;
    private ProjectUserRoleModel $roles;
    private int $alice;
    private int $bob;
    private int $p1;
    private int $p2;
    private int $p3;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new UserModel($this->container);
        $this->projects = new ProjectModel($this->container);
        $this->roles = new ProjectUserRoleModel($this->container);
        $this->alice = $users->create(['username' => 'alice']);
        $this->bob = $users->create(['username' => 'bob']);
        $this->p1 = $this->projects->create(['name' => 'Alpha']);
        $this->p2 = $this->projects->create(['name' => 'Beta']);
        $this->p3 = $this->projects->create(['name' => 'Gamma']);
        $this->scope = new ScopeResolver($this->container);
    }

    private function loginAs(int $userId): void
    {
        $this->container['userSession']->initialize($this->container['userModel']->getById($userId));
    }

    /** Agents' version_1 DDL, run from the Agents plugin's own schema file. */
    private function createAgentsTable(): void
    {
        if (! class_exists('\Kanboard\Plugin\Agents\Model\AgentTable')) {
            $this->markTestSkipped('Agents plugin is not autoloadable');
        }
        require_once __DIR__.'/../../Agents/Schema/Sqlite.php';
        \Kanboard\Plugin\Agents\Schema\version_1($this->container['db']->getConnection());
    }

    private function agent(int $ownerId, string $username): int
    {
        $id = (new UserModel($this->container))->create(['username' => $username]);
        (new \Kanboard\Plugin\Agents\Model\AgentTable($this->container))->insert($ownerId, $id, 'bot');
        return $id;
    }

    private function groupRole(int $projectId, int $userId, string $role): void
    {
        $groupId = (new GroupModel($this->container))->create('Group '.$projectId);
        (new GroupMemberModel($this->container))->addUser($groupId, $userId);
        (new ProjectGroupRoleModel($this->container))->addGroup($projectId, $groupId, $role);
    }

    private function assertForbidden(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function testSelfSeesOwnProjectsIncludingClosed()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->roles->addUser($this->p2, $this->alice, Role::PROJECT_MEMBER);
        $this->projects->disable($this->p2);
        $this->loginAs($this->alice);

        $scope = $this->scope->forUserPage($this->alice, null, null);

        $this->assertSame([$this->alice], $scope['user_ids']);
        $this->assertSame([$this->p1, $this->p2], $scope['project_ids']);
        $this->assertSame([$this->p1 => 'Alpha', $this->p2 => 'Beta'], $scope['projects']);
    }

    public function testSelfSeesProjectsReachedThroughGroup()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->groupRole($this->p3, $this->alice, Role::PROJECT_VIEWER);
        $this->loginAs($this->alice);

        $scope = $this->scope->forUserPage($this->alice, null, null);

        $this->assertSame([$this->p1, $this->p3], $scope['project_ids']);
        $this->assertSame([$this->p1 => 'Alpha', $this->p3 => 'Gamma'], $scope['projects']);
    }

    public function testNoProjectsGivesEmptySet()
    {
        $this->loginAs($this->alice);

        $scope = $this->scope->forUserPage($this->alice, null, $this->p1);

        $this->assertSame([], $scope['project_ids']);
        $this->assertSame([], $scope['projects']);
    }

    public function testOwnerSeesOwnAgents()
    {
        $this->createAgentsTable();
        $bot = $this->agent($this->alice, 'alice-bot');
        $bot2 = $this->agent($this->alice, 'alice-bot-2');
        $bobBot = $this->agent($this->bob, 'bob-bot');
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->roles->addUser($this->p3, $bot, Role::PROJECT_MEMBER);
        $this->loginAs($this->alice);

        $this->assertSame([$bot, $bot2], $this->scope->ownedAgentIds($this->alice));

        $own = $this->scope->forUserPage($this->alice, null, null);
        $this->assertSame([$this->alice, $bot, $bot2], $own['user_ids']);
        $this->assertSame([$this->p1], $own['project_ids']);

        // Picking an own agent: just that agent, still limited to the viewer's projects.
        $agentPage = $this->scope->forUserPage($this->alice, $bot, null);
        $this->assertSame([$bot], $agentPage['user_ids']);
        $this->assertSame([$this->p1], $agentPage['project_ids']);

        $this->assertForbidden(fn () => $this->scope->forUserPage($this->alice, $bobBot, null));
    }

    public function testAgentsAbsentDegrades()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->loginAs($this->alice);

        $this->assertSame([], $this->scope->ownedAgentIds($this->alice));
        $this->assertSame([$this->alice], $this->scope->forUserPage($this->alice, null, null)['user_ids']);
        $this->assertForbidden(fn () => $this->scope->forUserPage($this->alice, $this->bob, null));
    }

    public function testNonAdminCannotTargetOtherUser()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MANAGER);
        $this->roles->addUser($this->p1, $this->bob, Role::PROJECT_MEMBER);
        $this->loginAs($this->alice);

        $this->expectException(AccessForbiddenException::class);
        $this->scope->forUserPage($this->alice, $this->bob, null);
    }

    public function testAdminTargetGetsAllProjectsAndTargetsAgents()
    {
        $this->createAgentsTable();
        $bobBot = $this->agent($this->bob, 'bob-bot');
        $this->agent(1, 'admin-bot');
        $this->roles->addUser($this->p1, $this->bob, Role::PROJECT_MEMBER);
        $this->projects->disable($this->p3);
        $this->loginAs(1);

        $scope = $this->scope->forUserPage(1, $this->bob, null);

        $this->assertSame([$this->bob, $bobBot], $scope['user_ids']);
        $this->assertSame([$this->p1, $this->p2, $this->p3], $scope['project_ids']);
        $this->assertSame([$this->p1 => 'Alpha', $this->p2 => 'Beta', $this->p3 => 'Gamma'], $scope['projects']);

        $this->assertSame([$this->bob, $bobBot], $this->scope->forUserPage(1, $this->bob, $this->p2)['user_ids']);
        $this->assertSame([$this->p2], $this->scope->forUserPage(1, $this->bob, $this->p2)['project_ids']);
        $this->assertSame([$bobBot], $this->scope->forUserPage(1, $bobBot, null)['user_ids']);
    }

    public function testProjectFilterOutsideSetIgnored()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->roles->addUser($this->p2, $this->alice, Role::PROJECT_MEMBER);
        $this->loginAs($this->alice);

        $outside = $this->scope->forUserPage($this->alice, null, $this->p3);
        $this->assertSame([$this->p1, $this->p2], $outside['project_ids']);

        $inside = $this->scope->forUserPage($this->alice, null, $this->p2);
        $this->assertSame([$this->p2], $inside['project_ids']);
        $this->assertSame([$this->p1 => 'Alpha', $this->p2 => 'Beta'], $inside['projects']);
    }

    public function testManagerAllowedOnProjectPage()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MANAGER);
        $this->loginAs($this->alice);

        $this->assertSame(['project_ids' => [$this->p1]], $this->scope->forProjectPage($this->alice, $this->p1));
    }

    public function testAdminAllowedOnProjectPageWithoutMembership()
    {
        $this->loginAs(1);

        $this->assertSame(['project_ids' => [$this->p3]], $this->scope->forProjectPage(1, $this->p3));
    }

    public function testGroupManagerAllowed()
    {
        $this->groupRole($this->p2, $this->alice, Role::PROJECT_MANAGER);
        $this->loginAs($this->alice);

        $this->assertSame(['project_ids' => [$this->p2]], $this->scope->forProjectPage($this->alice, $this->p2));
    }

    public function testMemberForbiddenOnProjectPage()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MEMBER);
        $this->groupRole($this->p2, $this->alice, Role::PROJECT_VIEWER);
        $this->loginAs($this->alice);

        $this->assertForbidden(fn () => $this->scope->forProjectPage($this->alice, $this->p1));
        $this->assertForbidden(fn () => $this->scope->forProjectPage($this->alice, $this->p2));
        $this->assertForbidden(fn () => $this->scope->forProjectPage($this->alice, $this->p3));
    }

    public function testManagerOfAOnlyGetsA()
    {
        $this->roles->addUser($this->p1, $this->alice, Role::PROJECT_MANAGER);
        $this->roles->addUser($this->p2, $this->alice, Role::PROJECT_MEMBER);
        $this->loginAs($this->alice);

        $this->assertSame(['project_ids' => [$this->p1]], $this->scope->forProjectPage($this->alice, $this->p1));
        $this->assertForbidden(fn () => $this->scope->forProjectPage($this->alice, $this->p2));
    }
}
