<li <?= $this->app->checkMenuSelection('PulseController', 'index', 'Pulse') ?>>
    <?= $this->url->link(t('Activity'), 'PulseController', 'index', ['plugin' => 'Pulse'] + ((int) $user['id'] !== (int) $this->user->getId() ? ['user_id' => $user['id']] : [])) ?>
</li>
