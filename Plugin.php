<?php
namespace Kanboard\Plugin\Pulse;

use Kanboard\Core\Plugin\Base;

class Plugin extends Base
{
    public function initialize(): void
    {
    }

    public function getPluginName(): string        { return 'Pulse'; }
    public function getPluginDescription(): string { return t('A GitHub-style activity heatmap for you, your agents and your projects.'); }
    public function getPluginAuthor(): string      { return 'Carmelo Santana'; }
    public function getPluginVersion(): string     { return '1.0.0'; }
    public function getPluginHomepage(): string    { return 'https://github.com/carmelosantana/kanboard-pulse'; }
    public function getCompatibleVersion(): string { return '>=1.2.47'; }
}
