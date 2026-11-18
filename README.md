# Pulse (Kanboard plugin)

A GitHub-style activity heatmap for you, your agents and your projects. Pulse turns what Kanboard
already records (the project activity stream, subtask timers and, with TimeReport, manually entered
time) into one 12-month contribution graph per person, coloured by person, type of work or project.

## What it shows

- **Your page** (dashboard sidebar and avatar menu, **Activity**): your graph, plus one graph per
  agent you own on the [Agents](https://github.com/carmelosantana/kanboard-agents) roster. A project
  filter narrows every graph to one of your projects.
- **Project page** (project menu, **Activity heatmap**, managers and admins only): one graph per
  person with activity in that project in the last 12 months, busiest first, counting only what
  happened inside that project. Former members and agents are included.
- **Admins** get a person picker on the Activity page and see anyone's graph across all projects.

Each graph covers the last 53 weeks, one column per week, days bucketed at local midnight in the
viewer's timezone. A day's intensity is its event count plus one per hour logged; the four shades are
quartiles of that person's active days, so a quiet person's graph is not washed out next to a busy
agent's. Each graph has a switch: colour by **person** (default), **type** (the kind of work that
dominated the day) or **project**. Hover or keyboard-focus a day for the breakdown, for example
`Nov 3, 2026 · 4 moves, 3 comments, 1.5 h logged · Website, Ops`. At phone width only the graph
scrolls sideways.

Under each graph a line totals the year (`42 events · 3.5 h logged in the last 12 months`) next to a
legend that follows the colour mode: the person's name, the types present in that graph, or its
projects (with **Other** for those beyond the eight colour slots).

Each grid is a single tab stop, on today. The arrow keys move between days (up and down by a day,
left and right by a week), Home and End jump to the first and last day, and the tooltip follows the
focused day. Escape closes the tooltip from anywhere on the page.

Colours are stable within a page and, as far as eight slots allow, across pages: a person or project
keeps its slot (picked by id) unless another one on the same page already took it, so a person's hue
can differ between two pages when slots collide.

What counts:

| Type | From | Rule |
|---|---|---|
| move | project activity | task moved to another column, position, swimlane or project; the rows one drag writes in the same second count once |
| task | project activity | task created, closed or reopened |
| comment | project activity | comment created |
| subtask | project activity | subtask created; subtask completed, once per subtask (the first time it was marked done) |
| file | project activity | file attached to a task |
| time | subtask timers | finished timers, dated when they stopped |
| time | TimeReport manual time | hours typed in by hand, dated when entered (needs TimeReport) |

Mentions, field-only task edits, deletions and overdue notices are not counted. Activity made with the
shared application API token has no user and never appears; agents with their own token are credited.

Pulse keeps no tables of its own: every page load aggregates from core's tables (and TimeReport's when
present) in PHP, so it works the same on SQLite, MySQL and Postgres and never drifts from the source.

## Install

Download `Pulse-1.0.0.zip` from the releases page, or install it from ModMenu, and unzip it into
`plugins/Pulse/` (the zip's single top-level folder is `Pulse`). Requires Kanboard `>=1.2.47` and PHP
`>=8.4`. No database migration and no configuration.

Pulse recommends, but does not require:

| Plugin | Version | Why |
|---|---|---|
| [Agents](https://github.com/carmelosantana/kanboard-agents) | `>=0.4.1` | shows your agents' graphs next to yours |
| [TimeReport](https://github.com/carmelosantana/kanboard-time-report) | `>=1.7.1` | counts manually entered time as time logged |

Without either, Pulse shows your own graph and counts timer hours only.

## Permissions

The visible projects are worked out first and passed to every activity query; nothing is read outside
them.

| Viewer | Activity page | Project page |
|---|---|---|
| Regular user | own graph and own agents' graphs, in the projects they belong to (active or closed); `user_id` of anyone else is 403 | 403 |
| Project manager | as a regular user | for projects they manage (directly or through a group): every active person, counting only that project |
| Admin | person picker; anyone's graph (that person plus their agents), across all projects | any project |

- The project filter on the Activity page is intersected with the viewer's projects; a project id from
  outside that set is refused, never widened.
- An agent's graph on its owner's page only covers the owner's projects.
- Regular members never see other members' graphs, even in shared projects.

## Adding an activity source (`pulse:sources`)

Another plugin can add a new kind of activity (git commits, deployments...) without touching Pulse.
Implement `Kanboard\Plugin\Pulse\Model\ActivitySource` and register a factory on the `pulse:sources`
hook from your `Plugin::initialize()`. Pulse reads the hook at request time, after every plugin has
loaded, so load order does not matter.

The contract:

```php
interface ActivitySource
{
    /** @return list<string> the type keys this source emits, e.g. ['commit'] */
    public function types(): array;

    /**
     * @param list<int> $userIds    users to include; [] means any user
     * @param list<int> $projectIds projects the viewer may see; never empty when Pulse calls you
     * @return list<array{user_id:int, project_id:int, ts:int, type:string, count:int, hours:float}>
     */
    public function fetch(array $userIds, array $projectIds, int $from, int $to): array;
}
```

- Return one row per event (`count` 1, `hours` 0) or per block of time (`count` 0, `hours` > 0), with
  `ts` a Unix timestamp in `[$from, $to]`. Pulse buckets by day in the viewer's timezone.
- Only query inside `$projectIds`; if it is empty, return `[]` without querying. Pulse also drops any
  row outside the requested projects, users or range, and any row with a missing id, `ts` or `type`,
  so a buggy source cannot leak or break activity.
- A source that throws loses only its own rows (the error is logged); the page still renders.
- Unknown type keys get a fallback colour and are labelled with the key in the tooltip.

A small example, counting commits a git plugin stores in its own table:

```php
namespace Kanboard\Plugin\GitLog;

use Kanboard\Core\Plugin\Base as PluginBase;

class Plugin extends PluginBase
{
    public function initialize()
    {
        // Guarded: without Pulse the hook is simply never read.
        $this->hook->on('pulse:sources', function () {
            return interface_exists('\Kanboard\Plugin\Pulse\Model\ActivitySource')
                ? new Model\CommitSource($this->container)
                : null;
        });
    }
}
```

```php
namespace Kanboard\Plugin\GitLog\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\Pulse\Model\ActivitySource;

class CommitSource extends Base implements ActivitySource
{
    public function types(): array
    {
        return ['commit'];
    }

    public function fetch(array $userIds, array $projectIds, int $from, int $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        $query = $this->db->table('gitlog_commits')
            ->columns('user_id', 'project_id', 'committed_at')
            ->in('project_id', $projectIds)
            ->gte('committed_at', $from)
            ->lte('committed_at', $to);
        if ($userIds !== []) {
            $query->in('user_id', $userIds);
        }

        return array_map(fn (array $commit) => [
            'user_id'    => (int) $commit['user_id'],
            'project_id' => (int) $commit['project_id'],
            'ts'         => (int) $commit['committed_at'],
            'type'       => 'commit',
            'count'      => 1,
            'hours'      => 0.0,
        ], $query->findAll());
    }
}
```

A listener that returns anything other than an `ActivitySource` (like the `null` above) is skipped.

## For theme authors

The graph partial (`Pulse:pulse/graph`) is plain markup styled by `Assets/css/pulse.css`. On the
project page the graphs sit in `<div class="panel pulse-panel">`, so a theme that paints `.panel`
gives them its surface; target `.pulse-panel` to style that page only. Each
`.pulse-cell` is painted with `--pulse-empty` (from ShadcnTheme's `--border`, else core's
`--table-border-color`, else `#e5e5e5`) and its `::before` carries the hue (`--pulse-h`, else the
person colour `--pulse-c`) at an opacity set by `data-level` (0-4). The tooltip uses `--popover`,
`--popover-foreground` and `--border` when present. Cells carry `data-date`, `data-level`,
`data-type` and `data-project`. The footer (`.pulse-footer`) holds `.pulse-total` and one
`.pulse-legend[data-pulse-legend]` per mode (the inactive ones `hidden`); legend text uses
`--muted-foreground` and each `.pulse-swatch` carries the colour. The active mode button keeps the
text colour on `--accent` and shows the person colour as a 3px inset underline.

## Development

```sh
# from the suite harness root
./testing/run-plugin-tests.sh Pulse
node --test Pulse/Test/js/*.test.mjs

# demo data on the dev stack (kb-suite, http://localhost:8081); refuses to run anywhere else
docker exec kb-suite php /var/www/app/plugins/Pulse/Test/seed/seed-demo.php
```

The seed creates `demo.ana` (manager of "Demo Web"), `demo.ben` (member of "Demo Web" and "Demo
Ops", identifiers `PULSEDEMOWEB` / `PULSEDEMOOPS`) and `demo.ana.bot` (Ana's agent), with a year of
every activity type. Their dev-only password is in the script. Re-running rebuilds only the projects
with those identifiers, without duplicating anything. It refuses to run outside the dev container,
when a `webhook_url` is configured, when a "Demo Web"/"Demo Ops" project exists without the demo
identifier, or when a user with a demo username lacks the `pulse_demo` user metadata, and it drops every queued job so seeding sends no notifications or webhooks.

## License

MIT, see [LICENSE](LICENSE).
