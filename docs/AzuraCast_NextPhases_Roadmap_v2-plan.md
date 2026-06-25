# Next Phases — Implementation Plan (Roadmap v2)

**Source:** `docs/AzuraCast_NextPhases_Roadmap_v2.pdf` (June 2026)  
**Scope:** Everything after PR1–PR13. **PR6 (automated testing) is out of scope** per product direction.  
**Baseline:** v0.28.1 — clock wheel PR7–PR13 + Live tab UX shipped; queue-build and schedule time-picker fixes.

---

## 1 · Where we are

| Area | PDF score | Repo reality |
|------|-----------|--------------|
| Clock scheduling engine | 9/10 | PR1–8 + PR10–13 done |
| Separation & rotation | 8/10 | PR9 done (wheel/template/daypart; category filter in planner) |
| Daypart & templates | 8/10 | PR10 done |
| Audit & scheduling analytics | 8/10 | PR11–12 done (events + preview/analytics modals) |
| Music scheduling intelligence | 5/10 | No positive rotation goals, no energy crossfade |
| Ad monetisation | 1/10 | Ad **slot type** exists; no VAST/sponsor UI |
| Listener session analytics | 2/10 | **No session table** — blocker for §7 analytics |
| Now-playing / metadata | 6/10 | Standard AzuraCast + clock wheel labels |
| Crossfade intelligence | 3/10 | PR8 `cue_out` caps only |

**PDF master list items 1–24** below; item **#1 PR6** omitted.

---

## 2 · PDF vs repo — corrections (avoid wrong work)

| PDF claim | Actual repo state | Plan implication |
|-----------|-------------------|------------------|
| “Category enforcement is soft” | Planner **hard-filters** `type`, optional `category_id`, optional `playlist_id` in DQL | Gap is **operator UI**: `Entries.vue` has no category/playlist pickers — PDs cannot program “Power Current only” without API/DB |
| “PR6 tests missing” | `tests/Unit/ClockWheel*` etc. exist on branch | Ignored per direction |
| “Hard anchor type in schema” | No `hard`/`soft` anchor column; strictness = schedule `clock_wheel_mode` + non-flexible slot types (ID/ad) | “Hard anchor miss” = **late ID at :00** + strict slot behaviour, not a new anchor enum (unless you want one) |
| “Legal ID slot type” | `ClockWheelSlotTypes::Id` only | Needs new type or flags (`mandatory`, `sequential` rotation) |

---

## 3 · Recommended phases (build order)

### Phase A — Clock correctness & format programming (HIGH)

**Goal:** PD-trustworthy clocks and on-time top-of-hour IDs.  
**Estimated:** 3–4 weeks.

| # | Item (PDF #) | Status |
|---|--------------|--------|
| A1 | **Format category enforcement (2)** | Pending — UI for category/playlist per slot |
| A2 | **End-of-hour lookahead (3)** | **Done** — last music slot before hour end uses strict fit + longest-fit + enforce cap |
| A3 | **Legal / top-of-hour ID (4)** | **Done** — `legal_id` slot type, mandatory queue, promo/id fallback, sequential rotation |
| A4 | **Hard anchor miss recovery (11)** | Pending |
| A5 | **Top-of-hour compliance log (12)** | **Done** — analytics API + modal; 10s tolerance; `actual_play_at` via Liquidsoap feedback |

**Client decisions locked (June 2026):** `legal_id` is a distinct slot type at `0:00`; compliance tolerance **10s**; build A2+A3 together; defer broader analytics page work.

**Dependencies:** A2 before A5; A3 overlaps A2/A5.

**Do not break:** PR8 fit-to-window, calendar authority, emergency override (PR13).

---

### Phase B — Audience foundation (HIGH)

**Goal:** Unlock sponsor-facing analytics.  
**Estimated:** 2–3 weeks.

| # | Item (PDF #) | Work |
|---|--------------|------|
| B1 | **Listener session table (5)** | Migration: `listener_sessions` (hashed IP, mount, connected/disconnected, duration, geo, UA, referrer). Hooks from existing listener connect/disconnect paths. Privacy: hash IP, retention policy. |
| B2 | **Daypart audience report (8)** | Aggregate concurrent listeners by schedule daypart; trend + CSV export. |
| B3 | **Hourly listener heatmap (13)** | 7×24 grid from sessions; station Schedule or Analytics page. |

**Dependencies:** B1 blocks B2, B3, and Phase C item C1.

---

### Phase C — Rotation & scheduling polish (MEDIUM)

**Estimated:** 2–3 weeks (can overlap late Phase A).

| # | Item (PDF #) | Work |
|---|--------------|------|
| C1 | **Per-slot separation overrides (6)** | Columns on `station_clock_wheel_slots` (artist/title windows, enable flag); `SeparationRulesChecker` resolves slot → wheel → template → daypart; UI on slot row. |
| C2 | **Positive rotation goals (7)** | Per category: min plays per 24h; planner deprioritizes never-played in category; audit when goal missed. |
| C3 | **Clock performance vs audience (10)** | Join `clock_wheel_events` fallbacks/drift with session concurrency dips (hourly correlation chart). |
| C4 | **Song dropout tracking (14)** | Session ends &lt;30s after track start → aggregate per `song_id` / media. |

---

### Phase D — Revenue path (MEDIUM → LOW)

**Estimated:** 2–4 weeks depending on scope.

| # | Item (PDF #) | Work |
|---|--------------|------|
| D1 | **Sponsor management UI (9)** | Entity: sponsor spot, flight dates, weight, daypart link; wheel ad slot draws from sponsor pool (extends playlist + burn rate). |
| D2 | **Sponsor spot reporting (16)** | Impressions per spot/daypart; export. |
| D3 | **Pre-roll on stream join (24)** | Dynamic mount intro from sponsor pool (existing intro files). |
| D4 | **VAST / Triton (19)** | Ad slot HTTP VAST, parse audio URL, impression ping, no-fill house ad — **defer** until audience justifies CPM. |

**Suggestion:** Ship **D1 + D2** before VAST.

---

### Phase E — Quality of life (LOW)

| # | Item (PDF #) | Notes |
|---|--------------|-------|
| E1 | Weekly grid view (20) | Daypart/template × Mon–Sun visual; new page or Clock Wheels tab |
| E2 | Template export/import (21) | JSON/YAML portable template + slots |
| E3 | Crossfade intelligence (15) | Needs energy/tempo metadata on media |
| E4 | Geographic / mount / referrer (17–18, 22–23) | After B1 |
| E5 | Live tab: recent `clock_wheel_events` | Small API list + PR7 tab panel |

---

## 4 · Suggested release slices

| Release | Contents | User-visible outcome |
|---------|----------|----------------------|
| **0.29.0** | Phase A1 + A2 (category UI + end-of-hour) | Format clocks + IDs on time |
| **0.30.0** | A3–A5 + B1 | Legal ID + compliance report + sessions |
| **0.31.0** | B2–B3 + C1–C2 | Sponsor-ready daypart stats + slot separation + rotation floors |
| **0.32.0** | C3–C4 + D1–D2 | Analytics correlation + sponsor ops |
| **Later** | D3–D4, Phase E | Ads at scale + PD workflow extras |

Adjust if client wants **sponsors before** top-of-hour fix.

---

## 5 · Questions for you (need answers before coding)

### Clock / ID

1. **Top-of-hour priority:** Full **end-of-hour lookahead** first, or acceptable to ship **cue_out fade cap** only for v0.29?
2. **Legal ID:** New slot type `legal_id`, or extend `id` with checkboxes (Mandatory, Sequential rotation, Top-of-hour only)?
3. **Missed anchor recovery:** What should on-air do? (a) play next pool ID immediately, (b) station fallback playlist, (c) log only, (d) Liquidsoap interrupt?
4. **Tolerance:** Is **15 seconds** late acceptable for compliance reporting, or must it be **0–5s**?

### Format / categories

5. **Category model:** Use existing **Media Categories** (Music Files page) for “Power Current / Gold”, or separate **format pools** per station?
6. **Slot rules:** Require **category OR playlist** on every music slot, or allow type-only (current behaviour) for backward compatibility?

### Analytics / privacy

7. **Session retention:** How long keep `listener_sessions` (30 / 90 / 365 days)?
8. **GDPR:** OK to store **hashed IP + country/city**, or geo-only / no IP storage?
9. **First sponsor deliverable:** Daypart report (8), heatmap (13), or compliance log (12)?

### Advertising

10. **Revenue path:** Start **Option 2** (sponsor UI + rotation) only, or plan **VAST** in same quarter?
11. **Pre-roll (24):** Required for first sponsor contract, or mount static intro enough for now?

### Scope / process

12. **Weekly grid (20):** Must-have for next phase, or defer until A+B done?
13. **Multi-station:** Template export (21) needed for **second station on same install**, or separate deployments?

---

## 6 · Recommendations (product + engineering)

1. **Do Phase A before analytics** — late IDs are the most visible on-air bug; sponsors care about audience second.
2. **Treat A1 as UI + validation**, not new planner logic — saves ~40% effort vs PDF wording.
3. **B1 (sessions) is the analytics keystone** — without it, items 8–18 in the PDF are dashboards with no data.
4. **Defer VAST (19)** until B2 proves listenership; Option 2 is ~80% there with playlists + burn rate.
5. **Keep PR3 planner changes contained** — one service for lookahead + unit tests around hour boundaries (even if PR6 not a gate, add tests for A2/A3 only).
6. **Wire `actual_play_at` on events** when doing A3/A5 — field exists in PR11 design but playback hook may still be missing.
7. **Document “hard anchor”** as: non-flexible slot + strict schedule mode, not a new DB enum, unless client insists on MusicMaster-style hard/soft per slot.

---

## 7 · Verification (per phase)

| Phase | Verify |
|-------|--------|
| A | ID fires within tolerance at `:00` on test wheel; category slot never pulls wrong category; `clock_wheel_events` shows compliance rows |
| B | Sessions created on connect/disconnect; daypart report matches manual listener check |
| C | Slot separation overrides in preview + on-air; rotation goal surfaces under-played category |
| D | Sponsor flight dates respected; impression count matches queue plays |

---

## 8 · Related docs

- `docs/clock-wheels.md` — operational detail, PR map  
- `docs/clock-wheels-remaining-phases-v7.md` — PR7–13 status (complete)  
- `docs/AzuraCast_NextPhases_Roadmap_v2.pdf` — full roadmap source  
- `DEPLOYMENT.md` — release/version flow  

---

*Generated from Roadmap v2 PDF review + repo audit, June 2026.*
