<?php

namespace Kanboard\Plugin\Pulse\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Pulse\Model\PulseService;

/**
 * Activity heatmap pages: your own (plus agents; admins pick anyone) and a project's.
 */
class PulseController extends BaseController
{
    public function index(): void
    {
        $viewerId = $this->userSession->getId();
        $targetId = $this->request->getIntegerParam('user_id') ?: null;
        $projectId = $this->request->getIntegerParam('project_id') ?: null;
        $data = (new PulseService($this->container))->userGraphs($viewerId, $targetId, $projectId);
        $target = $targetId ?? $viewerId;
        $isAdmin = $this->userSession->isAdmin();

        $this->response->html($this->helper->layout->dashboard('Pulse:pulse/user', $data + [
            'title' => t('Activity'),
            'user' => $this->userModel->getById($viewerId),
            'target_id' => $target,
            'is_admin' => $isAdmin,
            'users' => $isAdmin ? $this->userModel->getActiveUsersList() : [],
        ]));
    }

    public function project(): void
    {
        $project = $this->getProject();
        $data = (new PulseService($this->container))->projectGraphs($this->userSession->getId(), (int) $project['id']);

        $this->response->html($this->helper->layout->app('Pulse:pulse/project', $data + [
            'title' => $project['name'].' > '.t('Activity heatmap'),
            'project' => $project,
        ]));
    }
}
