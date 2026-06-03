# Clock Wheels — Remaining Phases (v7 PDF)

Source: `docs/AzuraCast_ClockWheel_Remaining_Phases_v7.pdf`  
**Scope:** PR7 and PR9–PR13 only. PR1–PR8 are treated as complete in that document.

This file maps each remaining phase to **this repo**, notes **gaps vs the PDF**, and defines a **implementation order** while waiting for client feedback on PR1 (migrations) and PR6 (tests).

---

## Foundation already in this repo (do not break)

| Area | Status | Key files |
|------|--------|-----------|
| Schema + `position_seconds` | Done | `Version20260519120000`, `StationClockWheelSlot` |
| Schedule conflicts | Done | `ScheduleConflictChecker`, calendar + API |
| Hour timeline / planner | Done | `ClockWheelPlaybackPlanner`, `Entries.vue` timeline bar |
| Scheduler + AutoDJ queue | Done | `ClockWheelScheduler`, `ClockWheelAnnotator` |
| Tests (PR6) | Done | `tests/Unit/ClockWheel*`, `ScheduleConflictCheckerTest`, functional Cest |
| PR8 enforcement | Done | `clock_wheel_enforce_cap`, `cue_out` via `ClockWheelAnnotator` |

See `docs/clock-wheels.md` for operational detail, tests, and monitoring.

---

## PDF vs repo — honesty check

| PDF claim | This repo |
|-----------|-----------|
| PR1–PR6 complete | **Mostly true** — tests exist; PR1 `position_seconds` restored by `Version20260519120000` (see client thread) |
| PR8 complete | **True** |
| PR7 “zero backend changes” | **Partially true** — tab can use existing queue/nowplaying/schedule APIs; PDF also references **`GET .../preview`** and **`clock_wheel_events`** which **do not exist yet** (PR12 / PR11) |
| PR5 preview endpoint | **Done** — `GET .../clock-wheel/{id}/preview` (PR12) |
| PR11 `clock_wheel_events` | **Implemented** — `clock_wheel_events` table + `ClockWheelEventLogger` hooks |
| PR9 `SeparationRulesChecker` | **Implemented** — per-wheel artist/title windows + burn-rate deprioritization |
| PR10 `ClockTemplate` / `ClockInstance` / `Daypart` | **Done** |
| PR13 `is_emergency` | **Done** |

**Architectural rule from PDF:** Do not change PR1–PR8 core behavior (fit-to-window, calendar authority, AutoDJ fallback). New work **extends** on top.

---

## Remaining phases (summary)

| PR | Name | Priority | Size | Backend | Frontend |
|----|------|----------|------|---------|----------|
| **7** | Schedule page — Live Clock Wheel tab | High | Medium | None required for MVP | New tab on `Schedule.vue` |
| **9** | Separation rules + burn rate | High | Medium | `SeparationRulesChecker` + planner hook | Config UI (wheel/template later) |
| **10** | Daypart clock inheritance | High | Medium | **Done** | Templates / dayparts / wheel link + schedule UX |
| **11** | Audit event database | High | Small | **Done** — `clock_wheel_events` + `ClockWheelEventLogger` | — |
| **12** | Preview simulator + analytics | Medium | Medium | Preview API + metrics queries | Dashboard + fill_strategy |
| **13** | Emergency override (optional) | Optional | Small | **Done** | Checkbox in `CreateEventModal` |

---

## Dependency order (from PDF)

```text
PR7  ─────────────────────────────┐
PR9  ─────────────────────────────┤  parallel (after PR1–8 stable)
PR10 ─────────────────────────────┤
PR13 ─────────────────────────────┘  optional, anytime after PR6

PR11 ──► PR12   (audit table before analytics/preview)
```

**Recommended implementation sequence:**

1. **PR7 (MVP)** — Done.
2. **PR11** — Done (`clock_wheel_events` + logger hooks).
3. **PR9** — Done.
4. **PR12** — Done.
5. **PR10** — **Done** (templates, dayparts, inheritance, operator polish — see PR10 section).
6. **PR13** — **Done**.

---

## PR7 — Schedule page: Live Clock Wheel tab

**Goal:** Read-only broadcast ops view on the Schedule page: circular clock face, now playing, current-hour slots with **real track names**, next hour / future wheels, conflict heads-up.

### APIs available today (no new backend)

| Data | API / source |
|------|----------------|
| Now playing (title, artist, clock wheel name) | Station now playing / public NP (`Profile/NowPlayingPanel.vue` patterns) |
| Upcoming queue rows | `GET /api/station/{id}/queue` (`Queue.vue`) |
| Calendar events (playlists + wheels) | `GET .../playlists/schedule`, `GET .../clock-wheels/schedule` (`Schedule.vue`) |
| Wheel detail + slots | `GET .../clock-wheel/{id}`, slots sub-resource |
| Active wheel on schedule | Compare current time to schedule feed + `Scheduler` rules (client-side or reuse schedule JSON) |

### Beyond MVP (implemented)

| Enhancement | Location |
|-------------|----------|
| **Current-hour preview** | Live tab calls `GET .../clock-wheel/{id}/preview?hour=` (station hour) — **Projected** column next to **Queued** |
| Conflict heads-up | Schedule feed `is_now` + playlist/streamer overlap warning |

### Optional later

| PDF reference | Notes |
|---------------|--------|
| `clock_wheel_events` on Live tab | Audit list endpoint not exposed; use Analytics modal or SQL |
| Conflict from PR2 | Calendar-derived warning only (no dedicated conflict API) |

### Implementation sketch

- **Done (MVP):** Tabbed UI on `Schedule.vue`, `Schedule/ClockWheelLiveTab.vue`, `useClockWheelLiveData.ts`.
- New component e.g. `frontend/components/Stations/Schedule/ClockWheelLiveTab.vue`:
  - CSS clock hand from station TZ + `position_seconds` on active wheel slots.
  - Poll now playing + queue every ~10s.
  - Resolve active wheel from schedule + `is_active`.
- **Do not** change `ClockWheels/EditModal` or slot editor.

### Done when (PDF)

Live hand in station TZ; now playing shows track + artist; current hour shows queued titles; next/future wheels listed; conflict warning when playlist/streamer wins.

---

## PR9 — Separation rules + burn rate protection

**Status: Done** — artist/title windows, burn-rate deprioritization, relaxation cascade, audit flags, **slot category separation**, **template default rules**, daypart overrides (PR10).

**Implemented:**

| Piece | Location |
|-------|----------|
| Checker | `SeparationRulesChecker.php` |
| Settings | `ClockWheelSeparationSettings` — resolve order: daypart override → wheel → **template defaults** |
| Category | When a slot pins `category_id`, same category in recent plays (artist window) is blocked |
| History | `StationQueueRepository::getRecentlyPlayedWithCategoryByTimeRange()` |
| Wheel UI | `EditModal` / `Form/Entries.vue` |
| Template UI | `TemplateEditModal` + migration `Version20260603120000` |
| Daypart UI | `DaypartEditModal` separation override section |

**Not in repo (PDF extras):** tempo / gender / decade separation — no matching fields on `StationMedia` today.

**Tests:** `SeparationRulesCheckerTest.php`, `ClockWheelSeparationSettingsTest.php`

---

## PR10 — Daypart clock inheritance

**Status: Done**

**Goal:** Reusable templates, dayparts that materialize hourly wheels, and inheritance so one template edit updates linked instances.

**Implemented:**

| Piece | Location |
|-------|----------|
| Migration | `Version20260601120000` — templates, template slots, dayparts, wheel FKs |
| Migration | `Version20260602120000` — daypart separation override columns |
| Entities | `StationClockWheelTemplate`, `StationClockWheelTemplateSlot`, `StationClockDaypart` |
| Wheel links | `template_id`, `daypart_id`, `hour_of_day`, `inherits_template_slots` on `StationClockWheel` |
| Services | `ClockWheelSlotWriter`, `ClockWheelInheritanceService` |
| Separation | `ClockWheelSeparationSettings::resolveForWheel()` — daypart wins when `separation_override_enabled` |
| API | `/clock-wheel-templates`, `/clock-dayparts`, `POST .../clock-daypart/{id}/sync`; wheel PUT accepts `template_id` + `inherits_template_slots` |
| UI — manage | `ClockWheels.vue` tabs: Wheels / Templates / Dayparts |
| UI — daypart | `DaypartEditModal` — template, AM/PM hour chips, separation override, **Re-sync wheels** (footer) |
| UI — daypart list | **Re-sync** per row (Actions column) |
| UI — manual wheel | `EditModal` + `Form/Entries.vue` — optional template link, **Inherit template slots** (read-only slots when on) |
| UI — schedule | `CreateEventModal` — start/end time chips auto-set from `hour_of_day` when picking a daypart wheel |
| Time UX | `AmPmTimeInput.vue`, `frontend/functions/amPmTime.ts` (dayparts + schedule) |
| Tests | `ClockWheelInheritanceServiceTest.php`, `ClockWheelSeparationSettingsTest.php` |

**Operator workflow:**

1. **Template** — define slot layout on Templates tab; saves propagate to inheriting wheels.
2. **Daypart** — pick template + hour range; save creates/updates `{Daypart} HH:00` wheels. **Re-sync** after template-only edits (list or edit modal).
3. **Schedule** — **Schedule → Create Event → Clock Wheel**; daypart wheels pre-fill a 1-hour window from `hour_of_day`.
4. **Manual wheel** — link to template + enable inherit on the wheel editor; daypart-generated wheels are managed via daypart (no template toggle on those rows).

**Behaviour:** Template slot PUT propagates to wheels with `inherits_template_slots = true`. Saving a daypart runs sync (same as Re-sync). Direct wheel slot edits clear inheritance. Daypart separation override applies to all hourly wheels in that daypart when enabled. Runtime still uses `StationClockWheel` + slots.

**Optional later (not PR10):** bulk “schedule all daypart wheels” on calendar; export/import between stations.

---

## PR11 — Audit event database

**Status: Done**

**Goal:** Table `clock_wheel_events` with columns from PDF (timestamp, wheel_id, slot_id, media_id, expected/actual time, drift_seconds, anchor_type, fallback_reason, separation_relaxed, burn_rate_warning).

**Implemented:**

| Piece | Location |
|-------|----------|
| Migration | `Version20260529120000` → table `clock_wheel_events` |
| Entity | `App\Entity\ClockWheelEvent` |
| Logger | `App\Radio\AutoDJ\ClockWheel\ClockWheelEventLogger` |
| Hooks | `ClockWheelPlaybackPlanner` (queue / defer / slot fallbacks), `ClockWheelScheduler` (schedule conflict, inactive wheel) |
| Tests | `tests/Unit/ClockWheelEventLoggerTest.php` |

`separation_relaxed` / `burn_rate_warning` default to `false` until PR9. `actual_play_at` reserved for a future playback hook (PR12).

**Verify on server:**

```sql
SELECT event_kind, fallback_reason, anchor_type, drift_seconds, event_timestamp
FROM clock_wheel_events
WHERE station_id = YOUR_STATION_ID
ORDER BY id DESC
LIMIT 20;
```

**Unblocks:** PR12 analytics, richer PR7 live tab, PR9/PR13 logging flags.

---

## PR12 — Preview simulator + analytics dashboard

**Status: Done (on `dev` after clean reset)**

**Goal:**

- `GET .../preview` — next-hour projection (titles, drift, separation/burn warnings).
- Analytics dashboard from `clock_wheel_events` (accuracy, missed hard anchors, fallbacks, drift, burn rate, etc.).
- Wheel-level `fill_strategy` enum: `conservative` | `aggressive`.

**Note:** PDF scopes preview to **next hour only** (not full week).

---

## PR13 — Emergency override (optional)

**Status: Done**

**Goal:** `is_emergency` on `StationSchedule`; `ClockWheelScheduler` yields when emergency schedule active; log `fallback_reason = emergency_override`; UI checkbox with warning.

**Implemented:** `is_emergency` on schedule rows; checkbox in `CreateEventModal` for playlist events; scheduler skips clock wheel when emergency window is active.

**PDF:** Boolean only — no numeric priority stack.

---

## What not to build (PDF — do not override without approval)

- Hard-cut music mid-song (use cue_out / defer / strict rules already in PR8).
- Same-hour slot wrap (fall through to AutoDJ).
- Numeric priority stacks (use PR13 boolean).
- Multi-station live clock sharing (export/import copies only).
- Reopen Liquidsoap unless PR12 proves drift in production.
- Sub-second sync guarantees.

---

## Suggested follow-up (post v7)

| Area | Notes |
|------|--------|
| CI | Wire Codeception into GitHub Actions |
| Migrations | Run `Version20260603120000` (template separation) on prod with PR10 migrations |
| PR7 | Optional recent `clock_wheel_events` list on Live tab |
| PR9 | Tempo/gender/decade if media metadata is added later |
| PR10 | Bulk calendar scheduling for daypart wheels |

---

## Verification commands (existing)

```bash
# Unit tests (clock wheel + conflicts)
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Unit/ClockWheel* tests/Unit/ScheduleConflict*"

# Functional clock wheel API
docker compose exec --user=azuracast web bash -c \
  "cd /var/azuracast/www && vendor/bin/codecept run tests/Functional/Api_Stations_ClockWheelsCest.php"

# Confirm position_seconds column
docker compose exec web bash -c \
  "mysql -e \"SHOW COLUMNS FROM station_clock_wheel_slots LIKE 'position_seconds';\" azuracast"
```

---

## Client messaging (remaining phases)

> PR1–PR8 foundation plus **PR7–PR13** are implemented (see sections above). **PR7 Live** tab shows queued and **projected** (current-hour preview). **PR9** adds template default separation, slot category rules, and daypart overrides. Operators use **Clock Wheels** templates/dayparts, Re-sync, and schedule integration. Run migrations through `Version20260603120000` on deploy; invest in CI and optional follow-ups from the table above.
