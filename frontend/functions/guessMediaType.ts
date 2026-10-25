import {MediaTypeValue} from "~/functions/mediaTypes.ts";

export type MediaTypeGuess = {
    type: MediaTypeValue,
    reason: 'name' | 'folder' | 'length' | null,
};

// Words that give a type away. Matched as whole words, so "kid" never counts as "id".
const NAME_WORDS: [MediaTypeValue, string[]][] = [
    ['ad', ['ad', 'ads', 'advert', 'adverts', 'advertisement', 'commercial', 'commercials', 'spot', 'spots', 'sponsor', 'psa']],
    ['id', ['id', 'ids', 'legal id', 'station id', 'sweeper', 'sweepers', 'stinger', 'stingers', 'jingle', 'jingles', 'liner', 'liners', 'toh', 'top of hour', 'top of the hour', 'imaging', 'bumper', 'bumpers']],
    ['promo', ['promo', 'promos', 'trailer', 'teaser']],
    ['talk', ['sermon', 'sermons', 'podcast', 'podcasts', 'episode', 'ep', 'devotional', 'devotionals', 'teaching', 'teachings', 'interview', 'interviews', 'homily', 'bible study']],
];

// Too common in song titles to trust in a file name, but clear enough as a folder name.
const FOLDER_ONLY_WORDS: [MediaTypeValue, string[]][] = [
    ['talk', ['talk', 'talks', 'speech', 'speeches', 'messages', 'shows', 'spoken word']],
];

const words = (text: string): string => ' ' + text.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim() + ' ';

const match = (text: string, lists: [MediaTypeValue, string[]][]): MediaTypeValue | null => {
    const haystack = words(text);
    for (const [type, list] of lists) {
        if (list.some((word) => haystack.includes(' ' + word + ' '))) {
            return type;
        }
    }
    return null;
};

/**
 * Best guess at a file's type, from its name, the folders it sits in and its length.
 * Only pre-fills the upload box; the user always has the last word.
 */
export const guessMediaType = (
    name: string,
    relativePath: string,
    currentDirectory: string,
    durationSeconds: number | null,
): MediaTypeGuess => {
    const baseName = name.replace(/\.[a-z0-9]+$/i, '');
    const fromName = match(baseName, NAME_WORDS);
    if (fromName) {
        return {type: fromName, reason: 'name'};
    }

    // The file's own folders (when a folder was dropped) win over the folder being uploaded into.
    const ownFolders = relativePath.split('/').slice(0, -1).reverse();
    for (const folder of [...ownFolders, ...currentDirectory.split('/').reverse()]) {
        const fromFolder = match(folder, NAME_WORDS) ?? match(folder, FOLDER_ONLY_WORDS);
        if (fromFolder) {
            return {type: fromFolder, reason: 'folder'};
        }
    }

    if (durationSeconds !== null && durationSeconds > 0) {
        if (durationSeconds <= 20) {
            return {type: 'id', reason: 'length'};
        }
        if (durationSeconds <= 90) {
            return {type: 'promo', reason: 'length'};
        }
        if (durationSeconds >= 20 * 60) {
            return {type: 'talk', reason: 'length'};
        }
    }

    return {type: 'music', reason: null};
};

/** Read an audio file's length in the browser, without uploading it. Null if it can't be read. */
export const readAudioDuration = (file: File, timeoutMs = 5000): Promise<number | null> => new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const audio = new Audio();
    const finish = (value: number | null) => {
        window.clearTimeout(timer);
        audio.removeAttribute('src');
        audio.load();
        URL.revokeObjectURL(url);
        resolve(value);
    };

    const timer = window.setTimeout(() => finish(null), timeoutMs);
    audio.preload = 'metadata';
    audio.addEventListener('loadedmetadata', () => finish(Number.isFinite(audio.duration) ? audio.duration : null), {once: true});
    audio.addEventListener('error', () => finish(null), {once: true});
    audio.src = url;
});
