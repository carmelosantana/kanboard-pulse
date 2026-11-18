# Changelog

## 1.0.0 — 2026-11-18

- First release: a GitHub-style 12-month activity heatmap per person, built on the fly from core's
  project activity, subtask timers and (with TimeReport) manually entered time. No tables of its own.
- Your Activity page (dashboard sidebar and avatar menu): your graph plus your agents' (with Agents),
  with a project filter. Admins get a person picker.
- Project "Activity heatmap" (project menu) for managers and admins: one graph per person active in
  that project, counting only that project.
- Colour by person, type or project; per-day breakdown on hover and keyboard focus; only the graph
  scrolls at phone width; ShadcnTheme light and dark tokens.
- `pulse:sources` hook for other plugins to add activity types (for example git commits).
