# Linear Log playout handoff — 2026-09-24 ~2:15am Chicago

Branch: `linear-log-playout` (based on `dev` at commit `f0b1e51`/`1c42e0f` — the
TOH fixes from tonight are included). Linear log work is commit `b4f7482`.
**Not pushed to GitHub yet** — push it first thing.

Plan page (keep using/updating this, it's the source of truth for decisions):
https://claude.ai/artifact/VXLsTPbZxLcdevTYFKNVni
The user answered all 6 open choices as "defaults" (see the page for what
each default is: 2-hour lock, drop-if->30s-cut, tempo±3%+1 promo fill,
requests stay on, rolling log not daily 3am, hand-edit UI in Phase 4).

User's core ask: the 24-hour Linear Log should work like FM automation logs
(they named RCS Zetta, WideOrbit, RadioBOSS, mAirList, SAM Broadcaster Pro,
Playout One, Myriad, Rivendell) — **the log is what plays**, not a forecast
that AutoDJ ignores. DMCA, AI DJ, AI News, listener requests and the
Top-of-Hour ID/swap all stay live; swap and DMCA replacements write back
into the log. The existing Linear Log page design/layout must NOT change
(see memory `keep-linear-log-page-design`) — only add markers/columns.

## What's built (Phase 1 + Phase 2, code complete, off by default)

New:
- `backend/src/Entity/StationLogEntry.php` — one row per planned log line.
- `backend/src/Entity/Migration/Version20260924070000.php` — creates
  `station_log_entries` + `station_queue.log_entry_id`.
- `backend/src/Radio/AutoDJ/LinearLog/LinearLogPlayout.php` — BuildQueue
  subscriber that supplies the next log line instead of the random pickers,
  when `linear_log_playout_enabled` is on.
- `backend/src/Radio/AutoDJ/LinearLog/LinearLogStore.php` — persistence:
  seed the builder's simulation from already-planned lines, apply a new
  plan, write log status back, map a log line to the report-entry shape.
- `backend/src/Sync/Task/ReconcileLinearLogTask.php` — every-minute task
  that marks lines aired/swapped/replaced/dropped from the real queue rows.

Modified:
- `StationQueue` — added `log_entry_id`.
- `StationBackendConfiguration` — added `linear_log_playout_enabled` (bool,
  default false). `linear_log_enabled` (the existing report toggle) is
  unchanged/separate — playout requires BOTH on.
- `LinearLogBuilder` — when playout is on: seeds the queue simulation with
  planned lines (`LinearLogStore::seedQueue`), and after building, calls
  `LinearLogStore::applyPlan` to persist the plan + get as-run history back
  into the snapshot entries array. `build()`/`buildOnce()` gained a
  `$rebuild` param (manual "Build and Refresh" button now passes `rebuild:
  true` via `BuildLinearLogMessage`, which re-plans everything past the
  2-hour lock window; the hourly cron does NOT pass rebuild, so it only
  extends).
- `ClockWheelScheduler::buildFromClockWheel` and
  `QueueBuilder::calculateNextSong` — both early-return when
  `LinearLogPlayout::ownsSelection($event)` is true, so the log picks
  first when playout is on.
- `backend/config/events.php` — registered `LinearLogPlayout` (listed
  before `QueueBuilder` in the service subscriber list — order matters for
  which gets the BuildQueue priority-0 slot first) and
  `ReconcileLinearLogTask` in the sync task list.
- `QueueController::listAction` — when playout is on, the Upcoming Queue
  page's rolling-hour window is filled with planned log lines (read-only
  rows, same pattern as the existing Strict/AI News forecast rows) so the
  Queue page always matches the Linear Log instead of picking independently.
- `LinearLogSettingsAction` — added `linear_log_playout_enabled` field.
- `FeatureSuiteController` — status endpoint returns `playout_enabled`;
  manual build endpoint now passes `rebuild: true`.
- Frontend (`LinearLog.vue`, `LinearLogSchedule.vue`, `useLinearLog.ts`,
  `entities/LinearLog.ts`): added a second switch next to the existing
  ON/OFF ("Log Controls Playout" / PLAYING LOG / FORECAST badge), ON AIR /
  AIRED / SWAPPED / REPLACED / DROPPED markers styled like the existing
  NEXT/LIVE QUEUE markers, a LOCKED badge, and "Aired at" / "Status" as
  opt-in columns in the existing Columns menu. **Layout/design otherwise
  untouched.**

All backend files pass `php -l`. Frontend `vite build` succeeded cleanly on
the host (`node_modules/.bin/vite build` from `/var/azuracast`).

## NOT done yet — do these first in the next session

1. **Run the DB migration.** The command is
   `azuracast_cli azuracast:setup:migrate` (NOT `doctrine:migrations:migrate`
   — that namespace doesn't exist standalone here, confirmed live). Do this
   with the container's own `azuracast_cli` or via the deploy path in
   memory `azuracast-docker-deploy-path`, not raw `php bin/console`.
2. **Full container deploy of this branch's files.** Right now the *code*
   is copied into the running container's `/var/azuracast/www/...` (PHP
   classes + rebuilt `vite_dist`) and `composer dump-autoload -o` was run,
   and PHP workers were restarted — confirmed healthy (`/api/nowplaying/2`
   returns 200, Liquidsoap backend untouched/still running). But this was
   an ad-hoc file-by-file copy, not the normal deploy flow, and the
   migration was never run. Before relying on this further: re-verify the
   container's files still match this branch (diff each changed file
   against `docker exec azuracast cat ...`), then run the migration.
3. **Test with `linear_log_playout_enabled` still OFF first** — confirm
   normal AutoDJ/TOH behavior is unaffected (it should be, since
   `LinearLogPlayout::ownsSelection()` returns false immediately when the
   config flag is off). Then turn it on for this one station only and
   watch a build + a few AutoDJ cycles before trusting it further.
4. **Phase 3 (drop/fill at the hour post)** is mostly piggy-backed on
   existing TOH code (the swap, the new ±3%+promo fit, and
   `LinearLogPlayout::takeNext()`'s "drop lines left over from an hour that
   ended" logic) — but this has NOT been exercised end-to-end. Watch a real
   top-of-hour with playout on and confirm a log line that would run long
   gets dropped/swapped correctly and the log shows it.
5. **Phase 4 (hand-edit UI — replace/move/lock a log line)** is NOT started
   beyond the read-only `is_locked` field and badge already existing on the
   entity/frontend. Needs new API endpoints + UI controls on the Linear Log
   page (keep the existing page chrome, add row actions).
6. **PHPStan** was not run against these changes (no time). Worth a pass
   before considering this done — check `phpstan.neon` include paths cover
   the new `backend/src/Radio/AutoDJ/LinearLog/` directory.
7. Stray untracked files sitting in the repo root that are **NOT** part of
   this work and were deliberately left out of the commit — leave them
   alone unless the user says otherwise: `NextSongCommand_ORIGINAL.php`,
   `azuracast.env.backup`, `liquidsoap.liq`, `liquidsoap.liq.bak`,
   `liquidsoap_RESTORE.liq`, `liquidsoap_WORKING_RESTORE.liq`.

## Reminders for whoever continues this

- Never `git commit`/`push` without the user explicitly saying so in that
  moment (memory `no-git-without-explicit-go`) — this commit was made
  because the user explicitly asked to save to git now.
- Editing files under `/var/azuracast` on the host does NOT affect the
  running container — must `docker cp` + restart the right workers/services
  (memory `azuracast-docker-deploy-path`). For Liquidsoap config changes,
  restart only at a song change, never near a top of the hour (memory
  `restart-between-songs`).
- Keep the existing Linear Log page layout/design (memory
  `keep-linear-log-page-design`).
- Also outstanding from earlier tonight (separate from the linear log):
  2am/later TOH checks against the stream recordings in
  `/var/azuracast/backups/toh_captures/` were interrupted mid-check when
  the user redirected focus to the linear log — worth resuming.
