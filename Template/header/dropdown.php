<?php if ($this->user->getId()): ?>
<li>
    <?= $this->url->icon('th', t('Activity'), 'PulseController', 'index', ['plugin' => 'Pulse']) ?>
</li>
<?php endif ?>
