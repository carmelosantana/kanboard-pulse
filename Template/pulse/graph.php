<?php
use Kanboard\Plugin\Pulse\Model\Palette;
use Kanboard\Plugin\Pulse\Model\PulseService;

$userId = (int) $graph['user_id'];
$personColor = $user_colors[$userId] ?? Palette::OTHER;
$dates = array_keys($graph['days']);
$months = [];
foreach (array_chunk($dates, 7) as $week => $weekDates) {
    $month = substr($weekDates[0], 0, 7);
    if ($months === [] || end($months)['month'] !== $month) {
        $months[] = ['month' => $month, 'column' => $week + 1];
    }
}
// Drop a leading partial-month label that would collide with the next one.
if (count($months) > 1 && $months[1]['column'] - $months[0]['column'] < 3) {
    array_shift($months);
}
?>
<div class="pulse-graph" data-user-id="<?= $userId ?>" style="--pulse-c: <?= $this->text->e($personColor) ?>; --pulse-weeks: <?= (int) $graph['weeks'] ?>">
    <div class="pulse-graph-header">
        <h3 class="pulse-name"><?= $this->text->e($graph['name'] ?? '#'.$userId) ?></h3>
        <?php if ($graph['total'] > 0): ?>
        <div class="pulse-modes" role="group" aria-label="<?= t('Colour by') ?>">
            <button type="button" class="pulse-mode active" data-pulse-mode="person" aria-pressed="true"><?= t('Person') ?></button>
            <button type="button" class="pulse-mode" data-pulse-mode="type" aria-pressed="false"><?= t('Type') ?></button>
            <button type="button" class="pulse-mode" data-pulse-mode="project" aria-pressed="false"><?= t('Project') ?></button>
        </div>
        <?php endif ?>
    </div>

    <?php if ($graph['total'] <= 0): ?>
    <p class="pulse-empty"><?= t('No activity in the last 12 months') ?></p>
    <?php endif ?>
    <div class="pulse-body">
        <div class="pulse-weekdays" aria-hidden="true">
            <span></span><span><?= t('Mon') ?></span><span></span><span><?= t('Wed') ?></span><span></span><span><?= t('Fri') ?></span><span></span>
        </div>
        <div class="pulse-scroll">
            <div class="pulse-months" aria-hidden="true">
                <?php foreach ($months as $label): ?>
                <span style="grid-column: <?= (int) $label['column'] ?>"><?= $this->text->e(t((new \DateTimeImmutable($label['month'].'-01'))->format('M'))) ?></span>
                <?php endforeach ?>
            </div>
            <div class="pulse-grid">
                <?php foreach ($graph['days'] as $date => $day):
                    $tip = PulseService::dayTip($date, $day, $project_names);
                    $type = $day['top_type'];
                    $project = $day['top_project'];
                ?>
                <span class="pulse-cell" tabindex="0" role="img"
                      aria-label="<?= $this->text->e($tip) ?>"
                      data-date="<?= $this->text->e($date) ?>"
                      data-level="<?= (int) $day['level'] ?>"
                      data-type="<?= $this->text->e((string) $type) ?>"
                      data-project="<?= $project === null ? '' : (int) $project ?>"
                      data-type-color="<?= $type === null ? '' : $this->text->e(Palette::typeColour((string) $type)) ?>"
                      data-project-color="<?= $project === null ? '' : $this->text->e($project_colors[$project] ?? Palette::OTHER) ?>"
                      data-tip="<?= $this->text->e($tip) ?>"></span>
                <?php endforeach ?>
            </div>
        </div>
    </div>
    <?php if ($graph['total'] > 0):
        $legend = PulseService::legend($graph, $project_colors, $project_names);
        $legend['person'] = [['label' => $graph['name'] ?? '#'.$userId, 'color' => $personColor]];
    ?>
    <div class="pulse-footer">
        <p class="pulse-total"><?= $this->text->e(PulseService::totalLine($graph)) ?></p>
        <?php foreach (['person', 'type', 'project'] as $mode): ?>
        <div class="pulse-legend" data-pulse-legend="<?= $mode ?>"<?= $mode === 'person' ? '' : ' hidden' ?>>
            <?php foreach ($legend[$mode] as $item): ?><span class="pulse-legend-item"><span class="pulse-swatch" style="background: <?= $this->text->e($item['color']) ?>"></span><?= $this->text->e($item['label']) ?></span><?php endforeach ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>
</div>
