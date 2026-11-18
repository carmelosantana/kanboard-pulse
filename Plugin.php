<?php
namespace Kanboard\Plugin\Pulse;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->route->addRoute('pulse', 'PulseController', 'index', 'Pulse');
        $this->route->addRoute('pulse/project/:project_id', 'PulseController', 'project', 'Pulse');
        // Lets core's middleware and the menu template ask hasProjectAccess(); ScopeResolver still enforces it.
        $this->projectAccessMap->add('PulseController', 'project', Role::PROJECT_MANAGER);

        $this->hook->on('template:dashboard:sidebar', ['template' => 'Pulse:dashboard/sidebar']);
        $this->hook->on('template:header:dropdown', ['template' => 'Pulse:header/dropdown']);
        $this->hook->on('template:project:dropdown', ['template' => 'Pulse:project/dropdown']);
        $this->hook->on('template:layout:css', ['template' => 'plugins/Pulse/Assets/css/pulse.css']);
        $this->hook->on('template:layout:js', ['template' => 'plugins/Pulse/Assets/js/pulse.js']);
    }

    public function getPluginName(): string        { return 'Pulse'; }
    public function getPluginDescription(): string { return t('A GitHub-style activity heatmap for you, your agents and your projects.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-pulse'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
