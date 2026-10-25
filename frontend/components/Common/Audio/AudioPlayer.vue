<template>
    <audio
        ref="$audio"
        preload="none"
        :title="current.title ?? undefined"
    />
</template>

<script setup lang="ts">
import Hls from "hls.js";
import {onMounted, onScopeDispose, ref, useTemplateRef, watch} from "vue";
import {usePlayerStore} from "~/functions/usePlayerStore.ts";
import {storeToRefs} from "pinia";

const playerStore = usePlayerStore();
const {logVolume, isMuted, isPlaying, current, duration, progress} = storeToRefs(playerStore);
const {stop: storeStop, setIsPlaying, setPlayPosition} = playerStore;

const $audio = useTemplateRef('$audio');

const hls = ref<Hls | null>(null);
const bc = ref<BroadcastChannel | null>(null);

// Bumped on every stop/play, so an async callback can tell whether the
// playback attempt it belongs to is still the current one.
let playToken = 0;

watch(logVolume, (newVol) => {
    if ($audio.value !== null) {
        $audio.value.volume = newVol;
    }
});

watch(isMuted, (newMuted) => {
    if ($audio.value !== null) {
        $audio.value.muted = newMuted;
    }
});

const destroyHls = () => {
    if (hls.value !== null) {
        hls.value.destroy();
        hls.value = null;
    }
};

const stop = () => {
    playToken++;
    setIsPlaying(false);
    destroyHls();

    if ($audio.value !== null) {
        $audio.value.pause();
        // An empty src resolves against the page URL, so the browser fetches the
        // document as audio and errors; dropping the attribute releases cleanly.
        $audio.value.removeAttribute('src');
        $audio.value.load();
    }
};

const play = () => {
    const el = $audio.value;
    const url = current.value.url;

    if (el === null || url === null) {
        return;
    }

    const token = ++playToken;
    destroyHls();

    el.volume = logVolume.value;
    el.muted = isMuted.value;

    if (current.value.isHls) {
        if (Hls.isSupported()) {
            hls.value = new Hls();
            hls.value.loadSource(url);
            hls.value.attachMedia(el);
        } else if (el.canPlayType('application/vnd.apple.mpegurl')) {
            el.src = url;
            el.load();
        } else {
            console.log('Your browser does not support HLS.');
            return;
        }
    } else {
        // Firefox caches the downloaded stream, this causes playback issues.
        // Giving the browser a new url on each start bypasses the old cache/buffer
        el.src = navigator.userAgent.includes("Firefox")
            ? url + "?refresh=" + Date.now()
            : url;
        el.load();
    }

    setIsPlaying(true);

    el.play().catch((err: DOMException) => {
        // A newer attempt took over, or our own load() superseded this one.
        if (token !== playToken || err.name === 'AbortError') {
            return;
        }

        console.error('Unable to start playback.', err);
        stop();
    });

    if (bc.value) {
        bc.value.postMessage('played');
    }
};

watch(progress, (newProgress) => {
    if (newProgress.isSeek && $audio.value !== null) {
        $audio.value.currentTime = (newProgress.position / 100) * duration.value;
    }
});

watch(current, (newCurrent) => {
    if (newCurrent.url === null) {
        stop();
    } else {
        play();
    }
});

onMounted(() => {
    const el = $audio.value;

    if (el !== null) {
        el.onerror = () => {
            if (el.error?.code !== MediaError.MEDIA_ERR_NETWORK || !isPlaying.value) {
                return;
            }

            const token = playToken;
            const retryUrl = current.value.url;

            console.log('Network interrupted stream. Automatically reconnecting shortly...');
            setTimeout(() => {
                if (token === playToken && isPlaying.value && current.value.url === retryUrl) {
                    play();
                }
            }, 5000);
        };

        el.onended = () => {
            stop();
        };

        el.ontimeupdate = () => {
            const audioDuration = el.duration;

            setPlayPosition(
                (audioDuration !== Infinity && !isNaN(audioDuration)) ? audioDuration : 0,
                el.currentTime
            );
        };
    }

    // Allow pausing from the mobile metadata update.
    if ('mediaSession' in navigator) {
        navigator.mediaSession.setActionHandler('pause', () => {
            storeStop();
        });
    }

    if ('BroadcastChannel' in window) {
        bc.value = new BroadcastChannel('audio_player');
        bc.value.addEventListener('message', () => {
            storeStop();
        }, {passive: true});
    }
});

onScopeDispose(() => {
    if (bc.value) {
        bc.value.close()
    }
});
</script>
