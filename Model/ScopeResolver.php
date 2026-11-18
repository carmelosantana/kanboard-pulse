<?php

namespace Kanboard\Plugin\Pulse\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectModel;

/**
 * Permission gate: which users' activity a viewer may see, and in which projects.
 * Every activity query is limited to the project ids returned here.
 */
class ScopeResolver extends Base
{
    /**
     * Your page (or, for an admin, anyone's; for an agent owner, an own agent's).
     *
     * @return array{user_ids:list<int>, project_ids:list<int>, projects:array<int,string>}
     * @throws AccessForbiddenException
     */
    public function forUserPage(int $viewerId, ?int $targetUserId, ?int $projectId): array
    {
        $targetId = $targetUserId ?? $viewerId;
        $isAdmin = $this->userSession->isAdmin();

        if ($targetId !== $viewerId && ! $isAdmin && ! in_array($targetId, $this->ownedAgentIds($viewerId), true)) {
            throw new AccessForbiddenException();
        }

        $visible = $isAdmin
            ? array_map('intval', $this->projectModel->getAllIds())
            : array_map('intval', $this->projectPermissionModel->getProjectIds($viewerId));
        sort($visible);

        $projects = [];
        if ($visible !== []) {
            foreach ($this->db->hashtable(ProjectModel::TABLE)->in('id', $visible)->asc('id')->getAll('id', 'name') as $id => $name) {
                $projects[(int) $id] = $name;
            }
        }

        return [
            'user_ids' => array_values(array_unique(array_merge([$targetId], $this->ownedAgentIds($targetId)))),
            'project_ids' => $projectId !== null && in_array($projectId, $visible, true) ? [$projectId] : $visible,
            'projects' => $projects,
        ];
    }

    /**
     * Project page: managers of that project (directly or through a group) and admins.
     *
     * @return array{project_ids:list<int>}
     * @throws AccessForbiddenException
     */
    public function forProjectPage(int $viewerId, int $projectId): array
    {
        if (! $this->userSession->isAdmin()
            && $this->projectUserRoleModel->getUserRole($projectId, $viewerId) !== Role::PROJECT_MANAGER) {
            throw new AccessForbiddenException();
        }

        return ['project_ids' => [$projectId]];
    }

    /**
     * Agent user ids owned by a user; [] without the Agents plugin or its table.
     *
     * @return list<int>
     */
    public function ownedAgentIds(int $ownerId): array
    {
        if (! class_exists('\Kanboard\Plugin\Agents\Model\AgentTable')) {
            return [];
        }

        try {
            $rows = (new \Kanboard\Plugin\Agents\Model\AgentTable($this->container))->getByOwner($ownerId);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? array_map(fn ($row) => (int) $row['agent_user_id'], $rows) : [];
    }
}
