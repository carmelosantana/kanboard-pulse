<?php
/**
 * Seeds a year of demo activity for Pulse on the suite's dev stack (kb-suite, :8081).
 *
 *   docker exec kb-suite php /var/www/app/plugins/Pulse/Test/seed/seed-demo.php
 *
 * Creates (or refreshes) users demo.ana, demo.ben and the agent demo.ana.bot, the projects
 * "Demo Web" and "Demo Ops" (identifiers PULSEDEMOWEB / PULSEDEMOOPS), and 12 months of every
 * activity type Pulse counts. Re-running deletes and rebuilds only the projects carrying those
 * identifiers, so nothing is duplicated; the users are kept. Everything else is left alone.
 *
 * Guards, all checked before anything is written; each failure exits 1:
 * - CLI only, inside the suite's dev container: Docker's /.dockerenv plus the suite's own
 *   entrypoint (/usr/local/bin/entrypoint-dev.sh, mounted from testing/entrypoint-dev.sh), on SQLite;
 * - no webhook_url configured (the dev DB is an import of production);
 * - a project named "Demo Web" / "Demo Ops" without the demo identifier is never touched: refuse.
 * Core models queue event jobs (notifications, webhooks, other plugins' subscribers); the queue
 * is replaced by one that drops every job, so seeding notifies nothing and no one.
 */

use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectActivityModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\SubtaskTimeTrackingModel;
use Kanboard\Model\TaskModel;
use Kanboard\Model\CommentModel;

const DEMO_PASSWORD = 'pulse-demo-2026'; // dev-only, demo users only
const DEMO_PROJECTS = ['PULSEDEMOWEB' => 'Demo Web', 'PULSEDEMOOPS' => 'Demo Ops']; // identifier => name
const KANBOARD_ROOT = '/var/www/app';

function refuse(string $why): never
{
    fwrite(STDERR, "seed-demo: refusing to run: $why\n");
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    refuse('CLI only');
}
if (! is_file('/.dockerenv') || ! is_file('/usr/local/bin/entrypoint-dev.sh')) {
    refuse('not the kb-suite dev container (/.dockerenv and /usr/local/bin/entrypoint-dev.sh expected)');
}

require KANBOARD_ROOT.'/app/common.php';

if (DB_DRIVER !== 'sqlite') {
    refuse('dev stack runs on SQLite, found '.DB_DRIVER);
}

/** @var Pimple\Container $container */
$db = $container['db'];

if (trim((string) $container['configModel']->get('webhook_url')) !== '') {
    refuse('a webhook_url is configured; seeding could reach a real endpoint');
}

foreach (DEMO_PROJECTS as $identifier => $name) {
    $project = $container['projectModel']->getByName($name);
    if ($project && $project['identifier'] !== $identifier) {
        refuse("a project named \"$name\" exists without the identifier $identifier; it is not seed data");
    }
    $tagged = $container['projectModel']->getByIdentifier($identifier);
    if ($tagged && $tagged['name'] !== $name) {
        refuse("project $identifier is named \"{$tagged['name']}\", expected \"$name\"");
    }
}

/** Drops every job: no notifications, webhooks or third-party subscribers fire while seeding. */
final class DroppingQueueManager extends Kanboard\Core\Queue\QueueManager
{
    public int $dropped = 0;

    public function push(Kanboard\Job\BaseJob $job)
    {
        $this->dropped++;
        return $this;
    }
}
$queue = new DroppingQueueManager($container);
unset($container['queueManager']); // may already be frozen; unset then set replaces it
$container['queueManager'] = $queue;

mt_srand(20261118); // same data shape on every run

// ---- users -------------------------------------------------------------------------------

function demoUser(Pimple\Container $c, string $username, string $name): int
{
    $user = $c['userModel']->getByUsername($username);
    if ($user) {
        $c['userModel']->update(['id' => $user['id'], 'password' => DEMO_PASSWORD, 'name' => $name, 'role' => Role::APP_USER, 'is_active' => 1]);
        return (int) $user['id'];
    }
    $id = $c['userModel']->create([
        'username' => $username,
        'password' => DEMO_PASSWORD,
        'name'     => $name,
        'role'     => Role::APP_USER,
    ]);
    if (! $id) {
        refuse("could not create $username");
    }
    return (int) $id;
}

$ana = demoUser($container, 'demo.ana', 'Ana (demo)');
$ben = demoUser($container, 'demo.ben', 'Ben (demo)');
$bot = demoUser($container, 'demo.ana.bot', "Ana's bot (demo)");

$agentTable = '\Kanboard\Plugin\Agents\Model\AgentTable';
if (class_exists($agentTable)) {
    (new $agentTable($container))->adopt($ana, $bot, 'claude'); // false when already on the roster
} else {
    fwrite(STDERR, "seed-demo: Agents not loaded, demo.ana.bot is a plain user\n");
}

// ---- projects (rebuilt every run) ----------------------------------------------------------

foreach (array_keys(DEMO_PROJECTS) as $identifier) {
    $project = $container['projectModel']->getByIdentifier($identifier); // never by name alone
    if ($project) {
        foreach ($db->table(TaskModel::TABLE)->eq('project_id', $project['id'])->findAllByColumn('id') as $taskId) {
            $container['taskFileModel']->removeAll($taskId);
        }
        $container['projectModel']->remove($project['id']);
    }
}

$web = (int) $container['projectModel']->create(['name' => 'Demo Web', 'identifier' => 'PULSEDEMOWEB'], $ana, true); // ana manages it
$ops = (int) $container['projectModel']->create(['name' => 'Demo Ops', 'identifier' => 'PULSEDEMOOPS']);
if ($web === 0 || $ops === 0) {
    refuse('could not create the Demo projects');
}
$container['projectUserRoleModel']->addUser($web, $ben, Role::PROJECT_MEMBER);
$container['projectUserRoleModel']->addUser($web, $bot, Role::PROJECT_MEMBER);
$container['projectUserRoleModel']->addUser($ops, $ben, Role::PROJECT_MEMBER);

// ---- activity helpers ---------------------------------------------------------------------

$now = time();
$today = (new DateTimeImmutable('today'));

/** A random moment on $daysAgo's working hours, never in the future. */
function moment(DateTimeImmutable $today, int $daysAgo, int $now): int
{
    $ts = $today->modify("-$daysAgo days")->getTimestamp() + mt_rand(8 * 3600, 19 * 3600);
    return min($ts, $now - mt_rand(60, 3600));
}

function event(Pimple\Container $c, int $projectId, int $taskId, int $userId, string $name, array $data, int $ts): void
{
    $data['task'] = $c['taskFinderModel']->getDetails($taskId);
    // createEvent() stamps time(); insert the same row back-dated instead.
    $c['db']->table(ProjectActivityModel::TABLE)->insert([
        'project_id'    => $projectId,
        'task_id'       => $taskId,
        'creator_id'    => $userId,
        'event_name'    => $name,
        'date_creation' => $ts,
        'data'          => json_encode($data),
    ]);
}

function newTask(Pimple\Container $c, int $projectId, int $userId, int $ts): int
{
    static $n = 0;
    $titles = ['Landing page copy', 'Fix login redirect', 'Invoice export', 'Backup rotation', 'Upgrade PHP',
        'Search filters', 'Onboarding email', 'Status page', 'Cache warmup', 'Audit log', 'Dark mode',
        'Billing webhook', 'Rate limits', 'Release notes', 'Docs sweep', 'Error budget'];
    $taskId = (int) $c['taskCreationModel']->create([
        'project_id' => $projectId,
        'title'      => $titles[$n++ % count($titles)].' #'.$n,
        'creator_id' => $userId,
        'owner_id'   => $userId,
    ]);
    $c['db']->table(TaskModel::TABLE)->eq('id', $taskId)
        ->update(['date_creation' => $ts, 'date_modification' => $ts, 'date_moved' => $ts]);
    event($c, $projectId, $taskId, $userId, 'task.create', ['task_id' => $taskId], $ts);
    return $taskId;
}

/** @var array<int, list<int>> project id => task ids */
$tasks = [$web => [], $ops => []];
/** @var array<int, list<int>> project id => open subtask ids */
$openSubtasks = [$web => [], $ops => []];
/** @var array<int, list<int>> project id => subtask ids */
$allSubtasks = [$web => [], $ops => []];
$hasManualLedger = true;
try {
    $db->table('timereport_manual_time')->columns('id')->limit(1)->findAll();
} catch (PicoDb\SQLException) {
    $hasManualLedger = false;
}

// A starting backlog, a year back.
foreach ([$web => $ana, $ops => $ben] as $projectId => $userId) {
    for ($i = 0; $i < 6; $i++) {
        $tasks[$projectId][] = newTask($container, $projectId, $userId, moment($today, 365, $now));
    }
}

/** One counted action by $userId in $projectId at $ts, of a weighted-random type. */
function act(Pimple\Container $c, array &$tasks, array &$openSubtasks, array &$allSubtasks, bool $ledger, int $projectId, int $userId, int $ts): void
{
    $roll = mt_rand(1, 100);
    $taskId = $tasks[$projectId][array_rand($tasks[$projectId])];
    $db = $c['db'];

    if ($roll <= 10) {                                   // task created
        $tasks[$projectId][] = newTask($c, $projectId, $userId, $ts);
    } elseif ($roll <= 42) {                             // moved to another column
        $columns = array_keys($c['columnModel']->getList($projectId));
        $task = $c['taskFinderModel']->getById($taskId);
        $dst = $columns[array_rand($columns)];
        $db->table(TaskModel::TABLE)->eq('id', $taskId)->update(['column_id' => $dst, 'date_moved' => $ts]);
        event($c, $projectId, $taskId, $userId, 'task.move.column',
            ['task_id' => $taskId, 'src_column_id' => $task['column_id'], 'dst_column_id' => $dst, 'position' => 1], $ts);
    } elseif ($roll <= 62) {                             // comment
        $commentId = (int) $c['commentModel']->create(['task_id' => $taskId, 'user_id' => $userId, 'comment' => 'Demo note.']);
        $db->table(CommentModel::TABLE)->eq('id', $commentId)->update(['date_creation' => $ts, 'date_modification' => $ts]);
        event($c, $projectId, $taskId, $userId, 'comment.create', ['comment' => $c['commentModel']->getById($commentId)], $ts);
    } elseif ($roll <= 70) {                             // closed or reopened
        $open = (int) $c['taskFinderModel']->getById($taskId)['is_active'] === 1;
        $db->table(TaskModel::TABLE)->eq('id', $taskId)->update(['is_active' => $open ? 0 : 1, 'date_completed' => $open ? $ts : null]);
        event($c, $projectId, $taskId, $userId, $open ? 'task.close' : 'task.open', ['task_id' => $taskId], $ts);
    } elseif ($roll <= 80) {                             // subtask created
        $subtaskId = (int) $c['subtaskModel']->create(['task_id' => $taskId, 'title' => 'Demo step', 'user_id' => $userId]);
        $openSubtasks[$projectId][] = $subtaskId;
        $allSubtasks[$projectId][] = $subtaskId;
        event($c, $projectId, $taskId, $userId, 'subtask.create', ['subtask' => $c['subtaskModel']->getByIdWithDetails($subtaskId)], $ts);
    } elseif ($roll <= 87 && $openSubtasks[$projectId] !== []) { // subtask completed
        $subtaskId = array_shift($openSubtasks[$projectId]);
        $db->table(SubtaskModel::TABLE)->eq('id', $subtaskId)->update(['status' => SubtaskModel::STATUS_DONE]);
        $subtask = $c['subtaskModel']->getByIdWithDetails($subtaskId);
        event($c, $projectId, (int) $subtask['task_id'], $userId, 'subtask.update', ['subtask' => $subtask, 'changes' => []], $ts);
    } elseif ($roll <= 90) {                             // file attached
        $fileId = (int) $c['taskFileModel']->uploadContent($taskId, 'notes-'.date('Ymd', $ts).'.txt', base64_encode("Demo attachment\n"));
        $db->table('task_has_files')->eq('id', $fileId)->update(['user_id' => $userId, 'date' => $ts]);
        event($c, $projectId, $taskId, $userId, 'task.file.create', ['file' => $c['taskFileModel']->getById($fileId)], $ts);
    } elseif ($allSubtasks[$projectId] !== []) {         // time logged: a timer, or typed in (TimeReport)
        $subtaskId = $allSubtasks[$projectId][array_rand($allSubtasks[$projectId])];
        $hours = mt_rand(2, 12) / 4;
        if ($ledger && mt_rand(0, 2) === 0) {
            $db->table('timereport_manual_time')->insert(['subtask_id' => $subtaskId, 'user_id' => $userId, 'hours' => $hours, 'entered_at' => $ts]);
        } else {
            $db->table(SubtaskTimeTrackingModel::TABLE)->insert([
                'user_id' => $userId, 'subtask_id' => $subtaskId,
                'start' => $ts - (int) ($hours * 3600), 'end' => $ts, 'time_spent' => $hours,
            ]);
        }
        $db->execute('UPDATE '.SubtaskModel::TABLE.' SET time_spent = time_spent + ? WHERE id = ?', [$hours, $subtaskId]);
    }
}

// ---- a year of activity -------------------------------------------------------------------

// [user, projects, weekday chance %, weekend chance %, min actions, max actions]
$people = [
    [$ana, [$web],       60, 10, 1, 6],
    [$ben, [$web, $ops], 45,  5, 1, 4],
    [$bot, [$web],       35, 30, 3, 12],
];
$db->startTransaction();
for ($daysAgo = 364; $daysAgo >= 0; $daysAgo--) {
    $weekend = (int) $today->modify("-$daysAgo days")->format('N') >= 6;
    foreach ($people as [$userId, $projects, $weekdayChance, $weekendChance, $min, $max]) {
        if (mt_rand(1, 100) > ($weekend ? $weekendChance : $weekdayChance)) {
            continue;
        }
        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            act($container, $tasks, $openSubtasks, $allSubtasks, $hasManualLedger,
                $projects[array_rand($projects)], $userId, moment($today, $daysAgo, $now));
        }
    }
}
$db->closeTransaction();

$count = fn (string $table, string $column, array $ids) => $db->table($table)->in($column, $ids)->count();
printf("seed-demo: users ana=%d ben=%d bot=%d; projects web=%d ops=%d\n", $ana, $ben, $bot, $web, $ops);
printf("seed-demo: %d activity rows, %d timers, %s manual time rows\n",
    $count(ProjectActivityModel::TABLE, 'project_id', [$web, $ops]),
    $db->table(SubtaskTimeTrackingModel::TABLE)->in('user_id', [$ana, $ben, $bot])->count(),
    $hasManualLedger ? (string) $db->table('timereport_manual_time')->in('user_id', [$ana, $ben, $bot])->count() : 'no');
printf("seed-demo: %d queued jobs dropped (no notifications or webhooks sent)\n", $queue->dropped);
