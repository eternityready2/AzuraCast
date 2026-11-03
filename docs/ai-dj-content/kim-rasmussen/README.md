# Kim Rasmussen — own content library

Backup of the content written for **Overnight DJ - Kim Rasmussen** (ai_dj id 7, station 1,
"Hymns and Favorites", 12:01–5:55 AM Central). Loaded into production on 2026-11-03.

One JSON file per category, each a list of `{"content": ..., "reference": ...}` — the same
fields as the `ai_dj_content` table. Every item is linked to Kim through `ai_dj_has_content`.

| File | Items | `reference` holds |
|---|---|---|
| comment.json | 319 | — |
| verse_comment.json | 319 | — (said after a Bible verse) |
| artist_comment.json | 316 | — (said after an artist bio; uses `{{artist}}`) |
| poem.json | 330 | poem title |
| quote.json | 331 | person quoted |
| prayer.json | 329 | Bible passage |
| encouragement.json | 319 | — |
| hymn_reflection.json | 339 | hymn title text, or empty for general lines |
| song_intro_template.json | 304 | — |
| post_song_template.json | 316 | — |
| joke.json | 337 | — |
| inspiration.json | 342 | — |
| testimony.json | 351 | person or Bible passage |
| story.json | 338 | Bible passage, hymn or person, or empty for parables |

Not in these files (also in Kim's library on production):
- 31,086 Bible verses, copied from the station's shared library.
- 22 song intros and 8 post-song lines, copied from the station's shared library.
