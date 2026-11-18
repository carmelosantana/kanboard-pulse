<div class="page-header">
    <h2><?= t('Activity') ?></h2>
</div>

<form method="get" action="<?= $this->url->dir() ?>" class="pulse-filters">
    <input type="hidden" name="controller" value="PulseController">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="plugin" value="Pulse">

    <?php if ($is_admin): ?>
    <label for="pulse-user"><?= t('Person') ?></label>
    <select name="user_id" id="pulse-user">
        <?php foreach ($users as $id => $name): ?>
        <option value="<?= (int) $id ?>"<?= (int) $id === (int) $target_id ? ' selected' : '' ?>><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>
    <?php endif ?>

    <label for="pulse-project"><?= t('Project') ?></label>
    <select name="project_id" id="pulse-project">
        <option value=""><?= t('All projects') ?></option>
        <?php foreach ($projects as $id => $name): ?>
        <option value="<?= (int) $id ?>"<?= (int) $id === $project_id ? ' selected' : '' ?>><?= $this->text->e($name) ?></option>
        <?php endforeach ?>
    </select>

    <button type="submit" class="btn"><?= t('Show') ?></button>
</form>

<div class="pulse-graphs">
    <?php foreach ($graphs as $graph): ?>
        <?= $this->render('Pulse:pulse/graph', [
            'graph' => $graph,
            'user_colors' => $user_colors,
            'project_colors' => $project_colors,
            'project_names' => $projects,
        ]) ?>
    <?php endforeach ?>
</div>
