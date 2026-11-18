<?php if ($this->user->hasProjectAccess('PulseController', 'project', $project['id'])): ?>
<li>
    <?= $this->url->icon('th', t('Activity heatmap'), 'PulseController', 'project', ['plugin' => 'Pulse', 'project_id' => $project['id']]) ?>
</li>
<?php endif ?>
