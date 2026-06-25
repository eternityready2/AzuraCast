# Eternity Ready — Unified Implementation Plan

**Sources:**

- [`docs/EternityReady_Master_Plan_v3.pdf`](EternityReady_Master_Plan_v3.pdf) — enterprise master plan (June 2026)
- [`docs/top-of-hour-id-plan.md`](top-of-hour-id-plan.md) — client feedback + locked decisions (this doc supersedes the earlier narrow scope)
- [`docs/AzuraCast_NextPhases_Roadmap_v2-plan.md`](AzuraCast_NextPhases_Roadmap_v2-plan.md) — freelancer v5 cross-reference

**Baseline:** v0.28.x — clock wheels PR1–PR13, Live tab, wheel-only `legal_id` + end-of-hour lookahead + compliance (A2/A3/A5 in repo terms).

**Client decisions locked:** June 2026 (§8).

---

## 0 · Executive summary

The client needs **one on-air timing fix** that works for **clock wheels and playlists**: songs must **finish before `:00`**, and a **Legal ID must fire at `:00`** without interrupting the current song. That is the **highest-impact release (v0.29)**.

The Master Plan calls this **A1 + A2** (end-of-hour lookahead + legal/top-of-hour ID). We already built the **wheel-only** version; **v0.29 extends the same logic station-wide** via `HourBoundaryPlanner` + station settings.

Everything else in the Master Plan (analytics B1–B10, playlist intelligence C1–C3, enterprise clock features, crossfade profiles) ships **after** on-air correctness is trusted.

| Track | Master Plan | Repo today | Next release |
|-------|-------------|------------|--------------|
| **On-air timing** | A1 + A2 | Wheel only | **v0.29** — station-wide |
| **Format programming UI** | A3 | Backend only | **v0.30** |
| **Analytics** | B1–B10 | Modal + 6 tabs | **v0.31–0.32** |
| **Playlist intelligence** | C1–C3 | Basic rotation | **v0.30–0.32** |
| **Enterprise clocks** | Phase D | PR1–13 done | **v0.34** |

---

## 1 · Problem (client feedback + Master Plan A1/A2)

Professional stations need this **top-of-hour sound**:

```text
  :59:45  Last music fades cleanly (no hard cut)
  :00:00  Legal ID — "Eternity Ready Radio"
  :00:10  First music of new hour
```

Today AzuraCast misses `:00` because:

| Path | Gap |
|------|-----|
| **Clock wheel** | A1/A2 logic exists **only when a wheel schedule is active** |
| **Playlist “once per hour”** | ~~15-minute fuzzy window~~ **Strict minute match when protection on**; `:00` playlists suppressed (Top of Hour ID handles legal_id) |
| **Standard rotation** | No duration check — 5-min song at `:56` runs past `:00` |
| **Interrupt playlist** | Works but client **rejects** as primary approach |

**Root cause:** `Queue.php` projects `expectedPlayTime` forward, but **playlist selection does not use that timeline** to avoid long songs before the hour. Clock wheels already do — inside `ClockWheelPlaybackPlanner` only.

---

## 2 · Locked product spec — Top of hour ID (station-wide)

Reconciles Master Plan **A2** with client questionnaire answers.

### 2.1 Station settings (`station_backend_configuration`)

| Setting | Default | Locked? |
|---------|---------|---------|
| `top_of_hour_id_enabled` | `false` | Toggle — Master Plan: “Require Station ID at top of hour” |
| `top_of_hour_id_mode` | `strict` | **Yes** — no interrupt at launch |
| `top_of_hour_lookahead_minutes` | `10` | **Yes** — start filtering music duration this many minutes before `:00` |
| `top_of_hour_compliance_tolerance_seconds` | `10` | **Yes** — operator-adjustable; shared by wheel + station compliance |
| `top_of_hour_id_max_seconds` | `60` | Buffer math for ID length (or auto from file) |

**ID source:** **`legal_id` media type only** (not a dedicated playlist). Files: `stationid-past-original`, `station i.d. 1 seth`, `station i.d. 2 adult`, `eternity ready radio legal id`, etc. Sequential rotation. Promo / generic `id` fallback if pool empty (never silence).

**Deferred v1:** dedicated ID playlist picker, `:30` bottom-of-hour toggle (Master Plan A2 — see §6.2), auto-insert `legal_id` slot on all templates when toggle on (UX convenience — §6.2).

### 2.2 Behaviour (strict mode)

```text
Example — hour ends 10:00, 10-minute lookahead from 9:50:

  9:50+  Music picks filtered: length ≤ seconds_until_10:00 − ID_buffer
  9:58   Last music ends before boundary
 10:00   Legal ID queued (expected_play_time = 10:00:00 exactly)
```

| Rule | Detail |
|------|--------|
| No interrupt | Never cut the song currently on air |
| No fit | **Shortest song + `cue_out` cap** at boundary (client locked) |
| No `legal_id` file | Promo/`id` fallback + compliance miss log |
| Wheel + toggle both on | **Wheel `legal_id` at `0:00` wins** that hour; station toggle covers other hours |
| `legal_id` slot rules | NEVER skippable, NEVER deferred by separation/burn (wheel path — already implemented) |

### 2.3 Master Plan A1 extras (wheel + station)

Master Plan adds a **per-wheel** setting we should align with station-wide lookahead:

| Setting | Master Plan default | Plan |
|---------|---------------------|------|
| End-of-hour finish buffer | 15s before `:00` (0–30s slider) | Add `top_of_hour_finish_buffer_seconds` (default **15**) — last music must **end** at least this many seconds before `:00` (complements 10-min lookahead) |
| Per-wheel toggle | ON in wheel editor | `end_of_hour_lookahead_enabled` on `StationClockWheel` — default ON |

**Two layers (not redundant):**

1. **Lookahead (10 min)** — don’t *start* songs that won’t finish in time.
2. **Finish buffer (15s)** — last music must *end* with headroom before the ID (Master Plan “sounds like :59:45 fade”).

---

## 3 · Technical architecture (v0.29 core)

### 3.1 `HourBoundaryPlanner` (new shared service)

Extract from `ClockWheelPlaybackPlanner`:

| Method | Purpose |
|--------|---------|
| `getPlannedTimelineEnd()` | Cumulative position in hour from queue + `expectedPlayTime` |
| `secondsUntilNextTopOfHour()` | Wall-clock math in station TZ |
| `isInLookaheadZone()` | Uses `top_of_hour_lookahead_minutes` |
| `maxDurationBeforeTopOfHour()` | Lookahead + finish buffer + ID length |
| `isTopOfHourIdDue()` | ID not yet queued this hour |
| `filterCandidatesByMaxDuration()` | Duration filter |
| `resolveMandatoryTopOfHourId()` | `legal_id` sequential + fallback chain |

### 3.2 AutoDJ subscriber order

```text
Requests (5)
Clock Wheel (3)           — unchanged; wheel legal_id wins when present
Top-of-Hour ID (2)        — NEW: station-wide mandatory ID at :00
QueueBuilder (0)          — MODIFIED: duration-aware music in lookahead zone
```

### 3.3 Crossfade tie-in (Master Plan §7 — ship with v0.29 or v0.33)

**`legal_id` quick-cut:** no crossfade into or out of legal ID (Master Plan: “any crossfade bleeds defeat A2”). Implement in `HourBoundaryAnnotator` / extend `ClockWheelAnnotator` when `legal_id` or `top_of_hour` flag set on queue row.

### 3.4 Compliance

- **On-time** = `actual_play_at` within `top_of_hour_compliance_tolerance_seconds` of expected `:00`.
- Unify wheel analytics constant with station setting (F1/F3).
- Extend reporting to **station-wide** top-of-hour (wheel + playlist paths).

---

## 4 · Delivery phases — on-air correctness (v0.29)

Maps **our F-phases** ↔ **Master Plan A1/A2** ↔ **repo roadmap A2/A3/A5**.

### F1 — Unified hour-boundary core (~1–1.5 wk) → **v0.29a**

- [x] `HourBoundaryPlanner` + unit tests
- [x] Refactor clock wheel planner (no behaviour change)
- [x] Migration: station settings (§2.1) + optional wheel `end_of_hour_*` fields
- [x] API + settings UI: enable, lookahead minutes, tolerance seconds, finish buffer seconds
- [x] `legal_id` quick-cut annotation (minimal crossfade)

**Exit:** Wheel regression green; settings persist.

### F2 — Playlist AutoDJ protection (~1.5–2 wk) → **v0.29** ★ **client priority**

- [x] `TopOfHourIdScheduler` (`BuildQueue` priority 2)
- [x] `QueueBuilder` duration filter in lookahead zone
- [x] Strict `:00` for ID (replace 15-min once-per-hour window when protection on)
- [x] `cue_out` cap for shortest-song fallback
- [x] Feedback hook for `actual_play_at` (non-wheel rows)
- [x] Upcoming Queue badge “Top of hour ID”

**Exit:** Playlist-only station — Legal ID within tolerance every hour; no interrupt.

### F3 — Polish (~1 wk) → **v0.29.x**

- [x] A4 hard anchor miss recovery (structured fallback log)
- [x] Station-wide compliance report (extend analytics modal or new B2 tab seed)
- [x] Operator guide + update `docs/clock-wheels.md`
- [x] Master Plan A3: category/playlist dropdowns on slot rows (v0.30)

**Exit:** 3-hour soak test; compliance % visible.

### F5 — Format programming UI (v0.30.0)

- [x] A3: category + playlist dropdowns on slot rows (`Entries.vue`)
- [x] Pool mode: **Restrict pool** vs **Run rotation rules** when playlist pinned
- [x] C2: per-slot separation overrides (artist/title minutes)
- [x] C3: ~~Smart Shuffle~~ — removed; use playlist **Avoid Duplicate Artists/Songs** instead
- [x] Preview wall-clock column + pinned-playlist schedule warnings
- [x] Strict `:00` for once-per-hour ID playlists (carried from F2)

**Exit:** PDs configure clock slots without API; preview shows projected wall times.

### F6 — Analytics reports (v0.31.0)

- [x] B1: Listener heatmap 7×24 (`/reports/overview/heatmap` + Overview tab)
- [x] B2: Station-wide clock wheel performance + Legal ID compliance (`/reports/overview/clock-performance`)
- [x] B3: Playlist performance + rotation equity (`/reports/overview/playlist-performance`)
- [x] B4: Song dropout &lt;30s (`/reports/overview/dropout`)

**Exit:** Station Statistics Overview exposes heatmap, clock performance, playlist equity, and dropout tabs.

### F7 — Listener intelligence + rotation goals (v0.32.0)

- [x] B5: Bot filtering — `analytics_exclude_bots` station setting; unique counts exclude crawlers in `RunAnalyticsTask`
- [x] B7: Repeat listener tracking — loyalty stats via `listener_hash` sessions
- [x] B8: Time-of-day growth trend — first vs second half of date range by hour
- [x] C1: Positive rotation goals — `rotation_goal_days` on playlists + AutoDJ filter in `QueueBuilder`

**Exit:** Listener Insights + Growth Trend tabs; playlist form rotation goal field.

### F8 — Retention, daypart audience, crossfade (v0.33.0)

- [x] B9: Listener retention curve (`/reports/overview/retention-curve`)
- [x] B10: Daypart audience report (`/reports/overview/daypart-audience`) — uses clock wheel dayparts
- [x] Crossfade §7: content-type matrix + `ContentTypeCrossfadeAnnotator` + Crossfade Profiles page
- [x] Playlist `crossfade_profile` field for named profile overrides

**Exit:** Retention + Daypart tabs; station Crossfade Profiles menu; AutoDJ applies matrix fades.

### F9 — Clock wheels enterprise + AzuraWheel UI (v0.34.0)

- [x] D1: Weekly program grid (`/clock-wheels/program-grid`) — Program Grid tab
- [x] Hard anchors, research scores, sound codes on slots + planner enforcement
- [x] Reconciliation log API + tab (`/clock-wheels/reconciliation-log`)
- [x] Live assist: recent wheel events on Schedule → Live tab
- [x] Wheel effectiveness score + grade (A–F) in analytics
- [x] Listener overlay on analytics (avg/peak hourly listeners)
- [x] Export/import JSON (`/clock-wheel/{id}/export`, `/clock-wheels/import`)
- [x] Hour distribution widget, validity badges, estimated loop time in slot editor
- [x] Preview: `estimated_loop_seconds` + `is_valid` fields

**Exit:** Program Grid + Reconciliation tabs; slot hard-anchor/research/code columns; export from wheel editor; Live tab shows recent audit events.

### F4 — Later (not v0.29)

- [ ] Bottom-of-hour `:30` ID (Master Plan A2 — separate toggle + pool)
- [ ] Auto-add `legal_id` slot at `0:00` when station toggle enabled
- [ ] Per-daypart enable
- [x] Positive rotation goals (C1) — v0.32

---

## 5 · Master Plan — full roadmap (post v0.29)

Status key: **Done** | **Partial** | **Pending** | **Skip**

### Phase A — On-air correctness (Master Plan §2)

| ID | Item | Status | Notes |
|----|------|--------|-------|
| A1 | End-of-hour lookahead | **Partial** | Done on wheel; **F1–F2** station-wide + finish buffer |
| A2 | Legal / top-of-hour ID | **Partial** | Wheel `legal_id` done; **F2** station toggle + `legal_id` media |
| A3 | Category/playlist UI on slots | **Done** | v0.30 — `Entries.vue` dropdowns + pool mode |

### Phase B — Analytics to 10/10 (Master Plan §3)

**No new tables for B1–B9** (queries on existing `song_history`, `listener`, `analytics`, `clock_wheel_events`).

| ID | Item | Priority | Target |
|----|------|----------|--------|
| B1 | Listener heatmap 7×24 | HIGH | **Done** v0.31 |
| B2 | Clock wheel performance (station-wide) | HIGH | **Done** v0.31 — aggregates `clock_wheel_events` + top-of-hour compliance |
| B3 | Playlist performance + rotation equity | HIGH | **Done** v0.31 |
| B4 | Song dropout (&lt;30s) | HIGH | **Done** v0.31 |
| B5 | Bot filtering | HIGH | **Done** v0.32 |
| B7 | Repeat listener tracking | MEDIUM | **Done** v0.32 |
| B8 | Time-of-day growth trend | MEDIUM | **Done** v0.32 |
| B9 | Listener retention curve | MEDIUM | **Done** v0.33 |
| B10 | Daypart audience report | MEDIUM | **Done** v0.33 |
| — | Enterprise adds (tune-out viz, burnout prediction, wheel A–F grade, etc.) | MEDIUM | v0.31+ |

**Repo note:** B1 in Master Plan maps to roadmap **B3** (heatmap); roadmap **B1** (listener session table) is **not** required for B1–B9 per Master Plan — session table optional for deeper B7/B9.

### Phase C — Playlist intelligence (Master Plan §4)

| ID | Item | Target | Eternity Ready defaults |
|----|------|--------|-------------------------|
| C1 | Positive rotation goals (`rotation_goal_days`) | **Done** | v0.32 — playlist field + QueueBuilder filter |
| C2 | Per-slot separation overrides | **Done** | v0.30 — slot row + `SeparationRulesChecker` |
| C3 | Smart Shuffle | **Removed** | Use built-in playlist **Avoid Duplicate Artists/Songs** |
| — | Library aging, DNP list, playlist health monitor | v0.32+ | Operational polish |

**Master Plan QoL (§9):** Slot `playlist_id` should **respect playlist rotation order** — delegate to `QueueBuilder` when “Run rotation rules” selected (UI semantics pending in Phase D).

### Phase D — Clock wheels enterprise (Master Plan §5)

| Item | Target |
|------|--------|
| Wall-clock times in preview | **Done** | v0.30 |
| Schedule boundary warning (pinned playlist vs wheel window) | **Done** | v0.30 preview warnings |
| UI: “Restrict pool” vs “Run rotation rules” | **Done** | v0.30 slot Pool column |
| Weekly program grid (D1) | **Done** | v0.34 |
| Research score, sound codes, reconciliation log, hard anchors | **Done** | v0.34 |
| Live assist screen, wheel effectiveness score, copy/export JSON | **Done** | v0.34 |
| Recent events on Live tab, listener overlay on analytics | **Done** | v0.34 |

### Phase E — New features (Master Plan §6)

| Item | Target |
|------|--------|
| Station health dashboard | **Done** | v0.35 |
| Per-song restrictions (DNP) | **Done** | v0.35 |
| Holiday overrides | **Done** | v0.35 |
| Daypart rules in general AutoDJ | **Done** | v0.35 — extended history window from active daypart |
| Programme log | **Done** | v0.35 — merged into Song Playback Timeline (`/history`) |
| Stream quality | **Done** | v0.35 — mount bitrates on health dashboard |
| Listener webhooks | **Done** | existing `ListenerGained` / `ListenerLost` triggers |

### F10 — Phase E operational features (v0.35.0)

- [x] Station health dashboard (`/reports/overview/health`) — Health tab
- [x] Per-song do-not-play (`do_not_play`, reason, optional until) — media form + AutoDJ filter
- [x] Holiday overrides API + Schedule → Holidays tab
- [x] Programme log — merged into Song Playback Timeline (`/history`) with Playlist + Clock Wheel columns; duplicate analytics tab removed
- [x] Stream quality summary on health dashboard (mount format/bitrate)
- [x] Daypart separation history window for playlist-only AutoDJ hours
- [x] Listener webhooks — already shipped in core AzuraCast

**Exit:** Reports Health tab; Schedule Holidays tab; DNP on media edit; play log at Reports → Song Playback Timeline.

### Crossfade intelligence (Master Plan §7)

Content-type crossfade matrix (Music→ID quick-cut, etc.), per-playlist profile, profile manager UI — **Done** v0.33. **`legal_id` quick-cut** pulled forward to **v0.29** (§3.3).

### AzuraWheel UI features (Master Plan §8)

Hour distribution widget, smart weighted shuffle algorithm, validity badges, estimated loop time — **Done** v0.34 (distribution widget, validity badges, loop estimate; smart weighted shuffle deferred).

### What to skip (Master Plan §10)

FCC report, sub-second sync, multi-station live clock sharing, numeric priority stacks, VAST/Triton, pre-roll rebuild, audio-analysis crossfade — **unchanged, do not build**.

---

## 6 · Reconciliation notes (Master Plan vs repo vs client)

| Topic | Master Plan v3 | Repo / client decision |
|-------|----------------|------------------------|
| A1/A2 naming | A1=lookahead, A2=legal ID | Repo roadmap A2/A3 already shipped **wheel-only**; v0.29 = extend both station-wide |
| ID pool | “Dedicated playlist 3–5 IDs” | Client: **`legal_id` media type only** |
| Lookahead | Implied in A1 | Client: **10 minutes** (station setting) |
| Finish buffer | 15s default slider | Adopt as **`top_of_hour_finish_buffer_seconds`** (default 15) |
| Compliance | 10s mentioned in B2 | Client: **10s default, configurable** |
| Bottom of hour `:30` | A2 separate toggle | **Deferred** — not in v0.29 lock-in |
| PR6 tests | Listed “Done” in Master Plan | Run locally; CI wiring still pending (QoL §9) |
| `legal_id` in slot types | A2 spec | **Done** — `ClockWheelSlotTypes::LegalId` |
| Interrupt mode | Not recommended | Optional in schema only; **strict locked** |

---

## 7 · Recommended release order (merged)

Aligns Master Plan §11 with repo reality and client priority.

| Version | Ship | Outcome |
|---------|------|---------|
| **v0.29** | **F1 + F2** (A1+A2 station-wide) | Songs finish before `:00`; Legal ID at `:00` for wheels **and** playlists |
| **v0.30** | A3 + C2 + C3 + preview wall-clock + playlist UI semantics | PDs configure slots without API; smart shuffle |
| **v0.30.0** | **Shipped** — see §4.1 below | |
| **v0.31** | B1 + B2 + B3 + B4 | **Shipped** — heatmap, clock performance, playlist performance, dropout |
| **v0.32** | B5 + B7 + B8 + C1 | **Shipped** — bot filtering, loyalty, growth trend, rotation goals |
| **v0.33** | B9 + B10 + crossfade §7 | **Shipped** — retention curve, daypart audience, content-type crossfade |
| **v0.34** | Phase D enterprise + AzuraWheel UI | **Shipped** — grid, hard anchors, reconciliation, export/import |
| **v0.35** | Phase E | **Shipped** — health dashboard, DNP, holidays, programme log |

---

## 8 · Client decisions (locked — June 2026)

| # | Question | Decision |
|---|----------|----------|
| 1 | Strict mode (no interrupt)? | **Yes** |
| 2 | ID source | **`legal_id` media type only** |
| 3 | Wheel + station toggle | **Wheel wins** that hour |
| 4 | Lookahead | **10 minutes** |
| 5 | No song fits | **Shortest + fade/cap** |
| 6 | Compliance tolerance | **10s default**, setting `top_of_hour_compliance_tolerance_seconds` |

---

## 9 · Eternity Ready format defaults (from Master Plan)

Use when implementing C1/C2 (v0.30+):

| Category | Artist separation | Rotation goal |
|----------|-------------------|---------------|
| Power Current | 120 min | 3 days |
| Gold | 60 min | 7 days |
| ER Praise & Worship | 90 min | 5 days |
| Overnight | 20–30 min | Relaxed — avoid fallbacks at 3am |

**Overnight programming:** US sleeps but AU/DE/BR listen — use B10 daypart report to program overnight for international audience.

---

## 10 · Test plan (v0.29 acceptance)

1. Playlist-only, protection on, 200+ tracks — 3 hours, every `:00` Legal ID within tolerance.
2. Tolerance setting 5s / 15s — compliance report respects config.
3. Long song at `:55` — next picks finish before `:00` or cap.
4. Wheel `legal_id` + station toggle — no duplicate ID.
5. Protection off — legacy behaviour unchanged.
6. Empty `legal_id` library — promo fallback + compliance miss.
7. Finish buffer — last music ends ≥15s before `:00` when buffer=15.
8. `legal_id` crossfade — no bleed past `:00` on transition.

---

## 11 · Summary for client

> **v0.29** delivers what the Master Plan calls A1+A2 in one release: the same lookahead and Legal ID logic we already built for clock wheels, extended to **all AutoDJ** via a station **“Top of hour ID”** toggle. Strict mode (no interrupt), **`legal_id` files**, 10-minute lookahead, shortest-song fallback, and configurable compliance tolerance. Clock wheels keep working; playlist-only hours get the same protection. Analytics and rotation intelligence follow in v0.30–0.32 per the Master Plan — after on-air timing is trusted.

---

## 12 · Related docs

| Doc | Role |
|-----|------|
| [`docs/clock-wheels.md`](clock-wheels.md) | Operator + technical reference for wheels |
| [`docs/EternityReady_Master_Plan_v3.pdf`](EternityReady_Master_Plan_v3.pdf) | Full product vision |
| [`docs/AzuraCast_NextPhases_Roadmap_v2-plan.md`](AzuraCast_NextPhases_Roadmap_v2-plan.md) | Freelancer v5 cross-reference |
