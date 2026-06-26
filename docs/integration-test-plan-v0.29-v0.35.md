# Integration Test Plan — v0.29 through v0.35

**Product:** AzuraCast Custom (Eternity Ready)  
**Target version:** `0.35.0`  
**Scope:** Top-of-hour Legal ID, clock wheels, analytics, crossfade, enterprise clocks, Phase E ops  
**Related plan:** [`top-of-hour-id-plan.md`](top-of-hour-id-plan.md)

Use this document on a **non-production test station** with AutoDJ enabled. Mark each checkpoint as you go. When something fails, use the [issue report template](#issue-report-template) at the bottom and reference the **Step ID** (e.g. `1.2.3`, `5C.2`).

---

## How to navigate (verified UI paths)

| Feature | Menu / route |
|---------|----------------|
| Top of Hour ID | Station sidebar → **Top of Hour ID** (`/station/{id}/top_of_hour`) |
| Music / ID tagging | **Music Files** → edit file or bulk classify → media type **ID** |
| Queue | **Upcoming Queue** (`/station/{id}/queue`) |
| Clock Wheels | **Clock Wheels** (`/station/{id}/clock-wheels`) |
| Schedule + Live + Holidays | **Schedule** → tabs **Calendar**, **Live Clock Wheel**, **Holidays** |
| Crossfade Profiles | **Crossfade Profiles** (`/station/{id}/crossfade_profiles`) |
| Playlists | **Playlists** → edit playlist |
| Reports (analytics tabs) | **Reports** → **Station Statistics** (`/station/{id}/reports/overview`) |
| Song Playback Timeline (play log) | **Reports** → **Song Playback Timeline** (`/station/{id}/reports/timeline`) |
| Health | Same page → **Health** tab |

---

## Required test media library

Prepare media **before** Phase 0. Upload to **Music Files**, then set **media type** via edit file or **bulk classify** (toolbar). Top-of-hour IDs use the **ID** type (same as sweepers and jingles). Playlists alone do not substitute for ID files.

### Minimum inventory (quick reference)

| Media type | Min count | Typical duration | Required for |
|------------|-----------|------------------|--------------|
| **Music** | **200+** (rotation pool) | 2:30–4:00 avg | P0.4, Phase 1 soak, analytics, most phases |
| **Music** (long) | **1** | **4:00–6:00+** | Step 1.3 — hour-end cap / shortest-song fallback |
| **Music** (short) | **10+** | **≤ 2:30** | Steps 1.2.4–1.2.5 — lookahead picks before `:00` |
| **ID** | **3** | **10–60 s** each | P0.5, Phase 1, wheel `:00` slot (type **ID** at 0:00), crossfade 4.2 |
| **ID** (sweeper) | **2** | **5–30 s** | Wheel imaging slots between music blocks |
| **Promo** | **1** | **15–90 s** | Step 1.6 — fallback when no ID files; wheel promo slots |
| **Talk** | **2** (optional) | any | Wheel talk slots, crossfade matrix (optional) |
| **Ad** | **1** (optional) | any | Wheel ad slot type smoke test (optional) |

**Formats:** Any format AzuraCast already accepts on your station (typically **MP3**, **AAC**, **FLAC**, **OGG**). Re-encode test IDs as **44.1 kHz stereo** if you hear timing glitches; duration metadata must be correct after upload.

**Default scheduling limits (from settings):**

- **Max ID length for scheduling:** 60 s (`top_of_hour_id_max_seconds`) — IDs longer than 60 s may still queue (shortest-fit fallback) but prefer **≤ 60 s** for predictable `:00` timing.
- **Lookahead:** 10 min — from `:50` onward, music length is filtered.
- **Finish buffer:** 15 s — last music should end by `:59:45` before the ID.

### ID files (mandatory for Phase 1)

These are the **primary** top-of-hour source. Tag as media type **ID** (same type used for sweepers and jingles).

| # | Suggested purpose | Duration | Notes |
|---|-------------------|----------|-------|
| L1 | Main station ID | 15–30 s | e.g. “Eternity Ready Radio” full legal |
| L2 | Alternate ID (rotation) | 10–25 s | Second file to verify **sequential rotation** across hours |
| L3 | Third ID (rotation) | 10–25 s | Confirms order cycles L1 → L2 → L3 |

If you already have production files (e.g. `stationid-past-original`, `eternity ready radio legal id`), reuse those — tag all as **ID**.

**Verify:** Top of Hour ID page shows **ID files in library: 3** (or your count).

### Fallback chain (Step 1.6)

When **no** ID files exist, AutoDJ tries **Promo**, then logs error (silence risk).

| # | Type | Duration | Test use |
|---|------|----------|----------|
| F1 | **Promo** | 20–60 s | Temporarily disable all ID files → `:00` should play F1 |

For step **1.6**, disable or re-tag L1–L3 (not delete) so only F1 remains, then restore IDs after the test.

### Music library for rotation & hour-boundary tests

| Pool | Count | Artist / metadata | Playlist assignment |
|------|-------|-------------------|---------------------|
| **Main rotation** | 200+ | Mixed artists | **Playlist A** — Standard rotation, default order |
| **Short picks** | 10+ | Mixed; all **≤ 2:30** | Include in Playlist A (or tag in filename e.g. `SHORT-`) |
| **Long test track** | 1 | Any; **≥ 4:00** | **Playlist B** — Single-track or small pool for forcing 1.3 |
| **Rotation goal pool** | 5+ | Any | **Playlist D** — Set **Rotation goal (days)** = 7; play one track manually first |
| **DNP target** | 1 | Any track in active rotation | Same as Playlist A; flag **Do not play** on edit |

**Categories (optional, for clock wheel Phase 2):** Create 2 media categories (e.g. **Power Current**, **Gold**) and assign a subset of music to each if testing category-pinned wheel slots.

### Imaging / non-music (clock wheels Phase 2 & 5)

| # | Type | Duration | Wheel slot use |
|---|------|----------|----------------|
| I1 | **ID** | ~10 s | Sweeper between music blocks |
| I2 | **ID** | ~15 s | Second sweeper; hard-anchor tight window (5.6) |
| P1 | **Promo** | 30–60 s | Promo slot or fallback test |
| T1 | **Talk** (opt.) | 1–3 min | Talk slot smoke test |

Build a test wheel with slots such as:

```text
0:00  id         (type ID at 0:00 — mandatory top-of-hour)
0:02  music      (pinned Playlist A or category)
0:08  id         (I1 sweeper)
0:10  music
…     (fill hour to ~55:00)
0:55  music      (short slot — use Restrict pool or short tracks)
```

### Suggested test playlists

Create these on the test station and note names here:

| Playlist | Order | Tracks | Used in steps |
|----------|-------|--------|---------------|
| **TEST-Rotation** | Random / default | 200+ music | 1.2, 7.4, soak |
| **TEST-LongSong** | Sequential | 1 long + a few short | 1.3 |
| **TEST-RotationGoal** | Default | 5+; goal 7 days | 4.1 |
| **TEST-Holiday** | Default | 10+ distinct subset | 6.4.2 |
| **TEST-WheelPool** | Default | Mix of music + categories | 2.1, 5.x |

Schedule **TEST-Rotation** on calendar for playlist-only hours; schedule your test **clock wheel** for wheel hours (1.4, 5.x, 8.x).

### Preparation checklist

| # | Action | Done? |
|---|--------|-------|
| M1 | Upload L1–L3; set type **ID** | ☐ |
| M2 | Upload F1 (Promo), F2 (ID) for fallback | ☐ |
| M3 | Upload or identify 1 **long** music track (4+ min) | ☐ |
| M4 | Confirm 10+ **short** music tracks in rotation pool | ☐ |
| M5 | Pick 1 track for **Do not play** (still in playlist) | ☐ |
| M6 | Create test clock wheel with **ID** slot at `0:00` + music/id slots | ☐ |
| M7 | Assign playlists to Schedule calendar + one holiday override date | ☐ |
| M8 | Run **Bulk Media** or re-scan if durations show 0:00 after upload | ☐ |

### Media needed by test phase

| Phase | Step IDs | Minimum media |
|-------|----------|---------------|
| 0 | P0.4–P0.6 | 200+ music, 3× Legal ID, 1× Promo or ID |
| 1 | 1.2–1.5 | Above + short/long music pools; wheel with L1 at `0:00` for 1.4 |
| 1 | 1.6 | F1 Promo (and optionally F2 ID); Legal IDs temporarily disabled |
| 2 | 2.x | Wheel media + categories |
| 3 | 3.x | Any airtime history (run AutoDJ 1–2 days beforehand if reports empty) |
| 4 | 4.x | TEST-RotationGoal playlist; L1 + music for crossfade listen test |
| 5 | 5.x | Full test wheel; I1/I2 for hard anchor; export/import uses same JSON |
| 6 | 6.3 | DNP-flagged track in TEST-Rotation |
| 6 | 6.4 | TEST-Holiday playlist (10+ tracks) |
| 8 | 8.x | All of the above during multi-hour soak |

### What you do **not** need

- Separate audio files per report tab — analytics use **song_history** / listener data from normal playout.
- Dedicated “compliance” or “tolerance” files — tolerance is a **setting**, not media.
- Bottom-of-hour `:30` IDs — feature deferred; not in v0.35 scope.

---

## Phase 0 — Prerequisites

| ID | Checkpoint | Pass? |
|----|------------|-------|
| P0.1 | DB migration `Version20260616120000` applied (v0.35 DNP + holidays) | ☐ |
| P0.2 | App version shows **v0.35.0** (About / system info) | ☐ |
| P0.3 | `npm run build` succeeded; new sidebar items and report tabs visible | ☐ |
| P0.4 | Test station: AutoDJ **On**, backend running; **[Required test media library](#required-test-media-library)** prepared (200+ music, playlists, wheel) | ☐ |
| P0.5 | At least **3** files tagged **Legal ID** (see [Legal ID files](#legal-id-files-mandatory-for-phase-1)) | ☐ |
| P0.6 | At least **1 Promo** + **1 ID** file for fallback test (Step 1.6) | ☐ |
| P0.7 | Station timezone noted (all `:00` tests use **station TZ**) | ☐ |
| P0.8 | Optional: second browser/device to listen at `:00` | ☐ |
| P0.9 | [Media preparation checklist](#preparation-checklist) M1–M8 complete | ☐ |

---

## Phase 1 — Top of Hour Legal ID (v0.29)

### 1.1 Settings page

**Where:** Top of Hour ID

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 1.1.1 | Enable **Require Legal ID at top of hour** → Save | Setting persists after reload | ☐ |
| 1.1.2 | Set **Lookahead** = 10 min, **Finish buffer** = 15s, **Compliance tolerance** = 10s | Values persist | ☐ |
| 1.1.3 | Page shows **Legal ID files in library: N** with N > 0 | Count matches tagged files | ☐ |
| 1.1.4 | Change tolerance to **5s**, save, then **15s** | Later compliance reports use new value | ☐ |

### 1.2 Playlist-only soak (protection ON)

**Setup:** No clock wheel scheduled for test hours. One standard rotation playlist only.

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 1.2.1 | Run AutoDJ **3+ hours** | — | ☐ |
| 1.2.2 | Each `:00` — check **Upcoming Queue** | Legal ID row with badge **Top of Hour ID**; expected time **`:00:00`** | ☐ |
| 1.2.3 | Each `:00` — listen or check Song History / Song Playback Timeline | Legal ID plays within tolerance (default 10s) | ☐ |
| 1.2.4 | `:50–:59` window | No new long song starts that would run past `:00` | ☐ |
| 1.2.5 | Last music before ID | Ends **≥ finish buffer** (15s default) before `:00` | ☐ |
| 1.2.6 | Audio transition music → ID | No crossfade bleed past `:00` (quick-cut) | ☐ |

### 1.3 Long song near hour end

**Media:** One **4:00–6:00+** music track in **TEST-LongSong** (or main pool). See [Music library for rotation](#music-library-for-rotation--hour-boundary-tests).

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 1.3.1 | Long track (4+ min) starts near `:55` | — | ☐ |
| 1.3.2 | In lookahead zone | Shorter pick **or** `cue_out` cap before boundary | ☐ |
| 1.3.3 | At `:00` | Legal ID still on time | ☐ |

### 1.4 Wheel + station toggle (wheel wins)

**Setup:** Clock wheel with `legal_id` at `0:00` **and** station Top of Hour ID **both ON**, same hour.

| ID | Expected | Pass? |
|----|----------|-------|
| 1.4.1 | **One** Legal ID at `:00` only — wheel slot wins, no duplicate station ID | ☐ |

### 1.5 Protection OFF (regression)

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 1.5.1 | Disable **Require Legal ID at top of hour** | — | ☐ |
| 1.5.2 | Run 1 hour playlist-only | Legacy behaviour — no forced `:00` ID, no duration filtering | ☐ |

### 1.6 Empty Legal ID pool

**Media:** Disable or re-tag L1–L3; leave **F1 Promo** (and **F2 ID** as backup). See [Fallback chain](#fallback-chain-step-16).

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 1.6.1 | Temporarily remove/disable all **Legal ID** files | — | ☐ |
| 1.6.2 | Hit `:00` with protection ON | Promo / **ID** fallback plays — **not silence** | ☐ |
| 1.6.3 | Top of Hour page or Clock Performance tab | Compliance miss logged | ☐ |

---

## Phase 2 — Clock wheels core (v0.30)

### 2.1 Slot editor

**Where:** Clock Wheels → Edit wheel → **Basic Info** tab (entries table)

| ID | Feature | Test | Expected checkpoint | Pass? |
|----|---------|------|---------------------|-------|
| 2.1.1 | Category | Pin category on music slot | Saves; preview reflects type | ☐ |
| 2.1.2 | Per-slot separation | **Show per-slot separation** → override artist minutes | Slot uses override | ☐ |
| 2.1.3 | Layout widget | Edit slots | **Valid layout** / **Needs review** badge updates | ☐ |
| 2.1.4 | Est. loop | — | **Est. loop** time shown and reasonable | ☐ |

### 2.2 Preview

**Where:** Clock Wheels list → **Preview** button on a wheel

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 2.2.1 | **Wall clock** column shows projected times for next hour | ☐ |

### 2.3 Avoid duplicate artists (playlist)

**Where:** Playlists → edit → enable **Avoid Duplicate Artists/Songs**

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 2.3.1 | Enable **Avoid Duplicate Artists/Songs** on a rotation playlist | Saves | ☐ |
| 2.3.2 | Queue 20+ tracks from a pool with repeated artists | Same artist/title not repeated per playlist rules | ☐ |

### 2.4 Strict `:00` once-per-hour playlists

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 2.4.1 | ID playlist (once per hour) with protection ON | Fires at **`:00` only**, not fuzzy 15-min window | ☐ |

---

## Phase 3 — Analytics (v0.31–v0.33)

**Where:** Reports → **Station Statistics**  
**Date range:** Last 7–14 days with some airtime/listeners

| ID | Tab | Version | Expected checkpoint | Pass? |
|----|-----|---------|---------------------|-------|
| 3.1 | **Heatmap** | v0.31 | 7×24 grid loads, no API error | ☐ |
| 3.2 | **Clock Performance** | v0.31 | Fallback/deferred counts; Legal ID + top-of-hour compliance sections if data exists | ☐ |
| 3.3 | **Playlist Performance** | v0.31 | Play counts; rotation goal column | ☐ |
| 3.4 | **Dropouts** | v0.31 | Songs &lt;30s listen time or empty state | ☐ |
| 3.5 | **Listener Insights** | v0.32 | Loyalty stats load; note **Bots excluded** badge if enabled | ☐ |
| 3.6 | **Growth Trend** | v0.32 | First vs second half comparison by hour | ☐ |
| 3.7 | **Retention** | v0.33 | Retention curve chart loads | ☐ |
| 3.8 | **Daypart Audience** | v0.33 | Daypart breakdown (needs clock dayparts) | ☐ |
| 3.9 | **Streams** / **Clients** / legacy tabs | — | No regressions | ☐ |

**Cross-check:** Song Playback Timeline `:00` rows match Phase 1 audible history.

**Note (v0.32 bot filter):** `analytics_exclude_bots` defaults to **true** in backend. There is **no admin UI toggle** in v0.35 — Listener Insights shows a badge when bots are excluded. Do not expect a settings checkbox for this release.

---

## Phase 4 — Playlist intelligence + crossfade (v0.32–v0.33)

### 4.1 Rotation goal

**Where:** Playlists → edit → **Rotation goal (days)**

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 4.1.1 | Set goal (e.g. 7 days) | Saves; Playlist Performance tab shows goal | ☐ |
| 4.1.2 | Track played within goal window | Deprioritized / skipped in rotation | ☐ |

### 4.2 Crossfade profiles

**Where:** Crossfade Profiles

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 4.2.1 | Open page | Content-type matrix loads | ☐ |
| 4.2.2 | Edit Music→ID fades → Save | Settings persist | ☐ |
| 4.2.3 | Play music → Legal ID on air | Transition matches matrix (ID still quick-cut for legal rows) | ☐ |
| 4.2.4 | Playlist → **Crossfade profile** field | Named profile applies when set | ☐ |

---

## Phase 5 — Clock wheels enterprise (v0.34)

### 5.1 Program Grid

**Where:** Clock Wheels → **Program Grid** tab

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 5.1.1 | Week prev / this week / next navigation works | ☐ |
| 5.1.2 | Cells show daypart wheels; calendar overrides daypart colour | ☐ |
| 5.1.3 | Unprogrammed hours show empty cells | ☐ |

### 5.2 Enterprise slot fields

**Where:** Wheel editor → entries table

| ID | Field | Expected checkpoint | Pass? |
|----|-------|---------------------|-------|
| 5.2.1 | **Hard** checkbox | Saves and reloads | ☐ |
| 5.2.2 | **Research score** (0–100) | Saves and reloads | ☐ |
| 5.2.3 | **Sound code** (e.g. `P1`) | Saves; appears in Reconciliation **Code** column | ☐ |

### 5.3 Export / Import

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 5.3.1 | Edit wheel → **Export JSON** | JSON file downloads | ☐ |
| 5.3.2 | Clock Wheels list → **Import JSON** | New wheel created with slots | ☐ |
| 5.3.3 | Open imported wheel | Hard anchor, scores, codes intact | ☐ |

### 5.4 Reconciliation log

**Where:** Clock Wheels → **Reconciliation** tab

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 5.4.1 | Recent events load (track_queued, deferred, fallback) | ☐ |
| 5.4.2 | **Event kind** filter works | ☐ |
| 5.4.3 | **Code** column populated when slot has sound code | ☐ |

### 5.5 Live assist + wheel analytics

**Where:** Schedule → **Live Clock Wheel** (wheel must be on-air)

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 5.5.1 | Clock face / timeline; current segment highlighted | ☐ |
| 5.5.2 | **Recent wheel events** block updates (~10s poll) | ☐ |
| 5.5.3 | Clock Wheels list → **Analytics** on same wheel | Modal shows **effectiveness score** + grade **A–F** + listener avg/peak | ☐ |

### 5.6 Hard anchor (on-air, optional)

**Setup:** Slot marked **Hard** in a tight window (e.g. sweeper before next anchor).

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 5.6.1 | If anchor missed | Reconciliation shows `hard_anchor_missed` in **Reason** (kind may be fallback) | ☐ |

---

## Phase 6 — Phase E operations (v0.35)

### 6.1 Health dashboard

**Where:** Reports → Station Statistics → **Health** tab

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 6.1.1 | Status badge: **Healthy** / **Minor issues** / **Needs attention** / **Critical** | ☐ |
| 6.1.2 | Metrics: listeners now, media tracks, do-not-play, empty playlists | ☐ |
| 6.1.3 | Wheel fallbacks (7d), Legal ID compliance (7d), upcoming holidays, AutoDJ on/off | ☐ |
| 6.1.4 | **Stream quality (mounts)** table: mount name, format, bitrate | ☐ |

### 6.2 Song Playback Timeline (play log)

**Where:** Reports → **Song Playback Timeline**

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 6.2.1 | Rows: date/time, song, **Playlist** and **Clock Wheel** columns, listeners | ☐ |
| 6.2.2 | **Download CSV** includes Playlist and Clock Wheel columns | ☐ |
| 6.2.3 | Date range filter limits rows | ☐ |

### 6.3 Do-not-play (DNP)

**Media:** One track from **TEST-Rotation** flagged **Do not play** (still listed in playlist). See [Music library](#music-library-for-rotation--hour-boundary-tests).

**Where:** Music Files → edit file

| ID | Step | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 6.3.1 | Enable **Do not play** + **DNP reason** | Saves | ☐ |
| 6.3.2 | Track in active playlist | Never queues from playlist rotation | ☐ |
| 6.3.3 | Track in clock wheel pool | Excluded from wheel selection | ☐ |
| 6.3.4 | Set **DNP until (optional)** = 1 hour ahead | After expiry, track can play again | ☐ |
| 6.3.5 | Health tab | **Do-not-play** count ≥ 1 | ☐ |

### 6.4 Holiday overrides

**Media:** **TEST-Holiday** playlist (10+ tracks, distinct from normal rotation). Optional: holiday **clock wheel** using L1 at `0:00`.

**Where:** Schedule → **Holidays** tab

| ID | Scenario | Expected checkpoint | Pass? |
|----|----------|---------------------|-------|
| 6.4.1 | Add override for **today** with **Playlist override** | Saves | ☐ |
| 6.4.2 | Playlist-only hour on that date | Only holiday playlist runs | ☐ |
| 6.4.3 | Override with **Clock wheel override** on a day that normally has a wheel | Holiday wheel runs instead | ☐ |
| 6.4.4 | **Active** unchecked | Normal schedule resumes | ☐ |
| 6.4.5 | Delete override | Normal schedule resumes | ☐ |
| 6.4.6 | Health tab | **Upcoming holidays** count includes entry | ☐ |

### 6.5 Daypart rules in playlist AutoDJ (v0.35)

**Setup:** Daypart with separation enabled; **no** clock wheel that hour — playlist only.

| ID | Expected checkpoint | Pass? |
|----|---------------------|-------|
| 6.5.1 | Artist separation uses extended daypart history window (hard to verify in 1 hour — note obvious violations) | ☐ |

---

## Phase 7 — Schedule & regression smoke

| ID | Area | Expected checkpoint | Pass? |
|----|------|---------------------|-------|
| 7.1 | Schedule → Calendar | Create/edit wheel and playlist events | ☐ |
| 7.2 | Live tab — no wheel | Empty / inactive state OK | ☐ |
| 7.3 | Live tab — conflict | Warning when playlist/streamer conflicts with wheel | ☐ |
| 7.4 | Normal playlist AutoDJ | Still queues when no wheel / no holiday | ☐ |
| 7.5 | Media upload / edit (non-DNP) | Unchanged | ☐ |
| 7.6 | Webhooks (if configured) | Listener gained/lost still fire | ☐ |

---

## Phase 8 — End-to-end soak (run last)

Run **4–8 hours** (or overnight) with:

- [ ] Clock wheel scheduled part of day; playlists otherwise  
- [ ] Top of Hour ID enabled  
- [ ] At least one **Hard** anchor + one **DNP** track in library  
- [ ] One holiday override on a test date (optional)

| ID | When | Check | Pass? |
|----|------|-------|-------|
| 8.1 | Each hour | `:00` Legal ID within tolerance | ☐ |
| 8.2 | Hourly | Schedule → Live tab sane (no stuck state) | ☐ |
| 8.3 | After soak | Reconciliation: no unexpected fallback spike | ☐ |
| 8.4 | After soak | Song Playback Timeline matches audible history | ☐ |
| 8.5 | After soak | Health status ok or explainable warnings | ☐ |

---

## Priority order (if time is limited)

1. **Phase 0** prerequisites  
2. **Phase 1** Legal ID (core product promise)  
3. **Phase 6** Health, Timeline play log, DNP, Holidays (v0.35)  
4. **Phase 5** Program grid, Reconciliation, import/export (v0.34)  
5. **Phase 3** Analytics tabs (sample date range)  
6. **Phase 8** Soak  

---

## Issue report template

```text
## Issue N — [short title]
Step ID:        [e.g. 1.2.3, 6.3.2]
Version/feature: [e.g. v0.29 Top of Hour ID]
Setup:          [playlist-only / wheel / holiday / DNP / etc.]
Expected:       ...
Actual:         ...
Time (station TZ): YYYY-MM-DD HH:MM
Evidence:       [screenshot / queue row / browser console / API response]
Repro:          Always / Sometimes (N of M hours)
```

---

## Appendix A — Codebase verification (pre-test audit)

Each row was checked against the repo at **v0.35.0** before this document was written. Use this to know which steps are **UI/API confirmed** vs **runtime behaviour** (requires on-air testing).

| Area | Step IDs | Verified in code | Notes |
|------|----------|------------------|-------|
| Version | P0.2 | ✅ | `backend/src/Version.php` → `STABLE_VERSION = '0.35.0'` |
| Migration | P0.1 | ✅ | `Version20260616120000` — DNP columns + `station_holiday_overrides` |
| Top of Hour page | 1.1.* | ✅ | `TopOfHour.vue` — all setting fields + compliance widget |
| Top of Hour API | 1.1.* | ✅ | `backend/config/routes/api_station.php` + `TopOfHour/GetAction` |
| Queue badge | 1.2.2 | ✅ | `Queue.vue` — badge **Top of Hour ID** when `top_of_hour_legal_id` |
| Hour boundary backend | 1.2–1.6 | ⚠️ runtime | `HourBoundaryPlanner`, `TopOfHourIdScheduler`, `QueueBuilder` — needs on-air |
| Clock wheel slot UI | 2.1.* | ✅ | `ClockWheels/Form/Entries.vue` — pool mode, hard, score, code, separation |
| Preview wall clock | 2.2.* | ✅ | `PreviewModal.vue` — **Wall clock** + `projected_play_at` |
| Avoid duplicate artists | 2.3.* | ✅ | Built-in playlist **Avoid Duplicate Artists/Songs** (Smart Shuffle removed) |
| Report Summary tabs | 3.1–3.9 | ✅ | `Reports/Overview.vue` registers all tabs + API routes in `api_station.php` |
| Bot filter UI | 3.5 | ⚠️ partial | Backend default `analytics_exclude_bots = true`; **no settings UI** — badge only in Listener Insights |
| Rotation goal | 4.1.* | ✅ | Playlist form + `QueueBuilder` filter |
| Crossfade Profiles | 4.2.* | ✅ | `CrossfadeProfiles.vue` + menu entry |
| Program Grid | 5.1.* | ✅ | `ProgramGridTab.vue` + `/clock-wheels/program-grid` |
| Export JSON | 5.3.1 | ✅ | `EditModal.vue` → `/export` |
| Import JSON | 5.3.2 | ✅ | `ClockWheels.vue` → `/clock-wheels/import` |
| Reconciliation | 5.4.* | ✅ | `ReconciliationLogTab.vue` — kind filter + Code column |
| Live + recent events | 5.5.2 | ✅ | `Schedule/ClockWheelLiveTab.vue` — **Recent wheel events** |
| Wheel analytics grade | 5.5.3 | ✅ | `AnalyticsModal.vue` — `effectiveness_score`, `effectiveness_grade` |
| Hard anchor miss | 5.6.1 | ⚠️ runtime | `ClockWheelFallbackReason::HardAnchorMissed` — needs tight on-air scenario |
| Health dashboard | 6.1.* | ✅ | `HealthDashboardTab.vue` + `/overview/health` |
| Song Playback Timeline + CSV | 6.2.* | ✅ | `Timeline.vue` + `/history` (clock wheel column merged from programme log) |
| DNP media form | 6.3.1 | ✅ | `Media/Form/BasicInfo.vue` — do_not_play, reason, until |
| DNP AutoDJ filter | 6.3.2–3 | ⚠️ runtime | `MediaPlayability.php` — needs on-air |
| Holidays tab | 6.4.* | ✅ | `Schedule/HolidayOverridesTab.vue` + Schedule **Holidays** tab |
| Legal ID media type | P0.5 | ✅ | Single **ID** type in UI; legacy `legal_id` rows still read in backend |
| Fallback chain id → promo | P0.6, 1.6 | ✅ | `HourBoundaryLegalIdResolver.php` |
| ID max scheduling 60s default | Legal ID files | ✅ | `StationBackendConfiguration::$top_of_hour_id_max_seconds = 60` |
| Menu entries | all | ✅ | `menu.ts` — Top of Hour ID, Clock Wheels, Crossfade Profiles, Reports |

**Legend:** ✅ = UI/route/entity confirmed in repo · ⚠️ runtime = implementation exists; pass/fail requires live AutoDJ test

---

## Appendix B — Suggested one-day session

| Block | Time | Phases |
|-------|------|--------|
| 1 | 45 min | P0 + 1.1 + start 1.2 (1 hour soak) |
| 2 | 45 min | 1.3–1.6 edge cases |
| 3 | 60 min | 2.x + 3.x UI smoke (all report tabs) |
| 4 | 45 min | 5.x + 6.x (grid, DNP, holidays, export) |
| 5 | 3 hr | 8.x background soak — spot-check each `:00` |
| 6 | 30 min | 6.2 (Timeline) + 6.1 cross-check; file issues |
