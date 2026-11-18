<section id="main">
    <?= $this->projectHeader->render($project, 'TaskListController', 'show') ?>

    <div class="page-header">
        <h2><?= t('Activity heatmap') ?></h2>
    </div>

    <?php if (empty($graphs)): ?>
        <p class="alert"><?= t('No activity in this project in the last 12 months') ?></p>
    <?php else: ?>
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
    <?php endif ?>
</section>
