/**
 * The 24-hour log keeps a short technical note on each line ("Dropped: its
 * hour ran long"). The log's own code matches some of these by text, so they
 * stay as stored; this turns them into plain words for the page. An unknown
 * note is shown as it is.
 */
type Translate = (text: string, params?: Record<string, string>) => string;

export function linearLogPlainNote(note: string | null | undefined, $gettext: Translate): string {
    if (!note) {
        return '';
    }

    const exact: Record<string, string> = {
        'Dropped: a strict programme owns this air time': $gettext('Taken out: a show starts at this time.'),
        'Dropped: its hour ran long': $gettext('Taken out: the hour was already full.'),
        'Dropped: marked played but never aired': $gettext('Taken out: it never actually played.'),
        'Dropped: no audio from the stream during its window': $gettext('Taken out: the stream sent no audio during its time slot.'),
        'Dropped: removed by hand': $gettext('Removed by you.'),
        'Dropped: removed from the queue before it aired': $gettext('Taken out: it left the up-next list before it played.'),
        'Dropped: the line it replaced was put back by hand': $gettext('Taken out: you put back the song it had replaced.'),
        'Dropped: the planned file was removed from the library': $gettext('Taken out: its file was deleted from the media library.'),
        'Dropped: the planner could not keep it at its planned time': $gettext('Taken out: it could no longer play at its planned time.'),
        'Dropped: the show did not reach the air in its window': $gettext('Taken out: the show did not start during its time slot.'),
        'Filled: the hour was running short': $gettext('Added: the hour was short, so this fills it.'),
        'Live: AI DJ': $gettext('AI DJ talk, made live.'),
        'Live: listener request': $gettext('A listener request.'),
        'Live: picked by AutoDJ (no log line was ready)': $gettext('Picked live: nothing was planned for this moment.'),
        'Over: airs only if this hour makes up the time': $gettext('Extra: plays only if the hour has time left.'),
        'Refilled: replaces a dropped line': $gettext('Added: replaces a song that was taken out.'),
        'Replaced at air time: the planned song failed the DMCA rules': $gettext('Replaced at air time: the planned song would have broken the music licensing rules.'),
        'Locked by hand': $gettext('Locked by you.'),
    };
    if (note in exact) {
        return exact[note] as string;
    }

    const patterns: Array<[RegExp, (m: RegExpMatchArray) => string]> = [
        [/^Dropped: (.+) may not play at (.+)$/, (m) => $gettext('Taken out: %{source} isn\'t scheduled to play at %{time}.', {source: m[1] ?? '', time: m[2] ?? ''})],
        [/^Dropped: the same song airs within (\d+) minutes$/, (m) => $gettext('Taken out: the same song plays again within %{n} minutes.', {n: m[1] ?? ''})],
        [/^Dropped by log rule: (.+)$/, (m) => $gettext('Taken out by a log rule: %{reason}', {reason: m[1] ?? ''})],
        [/^Live: scheduled show \((.+)\)$/, () => $gettext('Played live as part of a scheduled show.')],
        [/^Live: picked by AutoDJ \((.+)\)$/, (m) => $gettext('Picked live: %{reason}', {reason: m[1] ?? ''})],
        [/^Replaced by hand; planned: (.+)$/, (m) => $gettext('Replaced by you (was: %{song}).', {song: m[1] ?? ''})],
        [/^Swapped at the top of the hour(.*)$/, (m) => $gettext('Swapped so the hour ends on time for the station ID%{rest}', {rest: m[1] ?? ''})],
    ];
    for (const [pattern, render] of patterns) {
        const match = note.match(pattern);
        if (match) {
            return render(match);
        }
    }

    return note;
}
