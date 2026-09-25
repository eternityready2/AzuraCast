<template>
    <svg
        :viewBox="`0 0 ${size} ${size}`"
        :width="size"
        :height="size"
        class="wheel-dial"
        role="img"
        :aria-label="ariaLabel"
    >
        <circle
            :cx="c"
            :cy="c"
            :r="r"
            fill="none"
            class="wheel-dial__track"
            :stroke-width="stroke"
        />
        <path
            v-for="(seg, i) in segments"
            :key="i"
            :d="seg.d"
            fill="none"
            :stroke="seg.color"
            :stroke-width="stroke"
            class="wheel-dial__segment"
            :class="{'is-selected': selectedIndex === seg.index}"
            @click="emit('select', seg.index)"
        >
            <title>{{ seg.title }}</title>
        </path>
        <g v-if="showTicks">
            <text
                v-for="t in ticks"
                :key="t.label"
                :x="t.x"
                :y="t.y"
                class="wheel-dial__tick"
                text-anchor="middle"
                dominant-baseline="middle"
            >{{ t.label }}</text>
        </g>
        <text
            v-if="centerLabel"
            :x="c"
            :y="c - (centerSub ? size * 0.04 : 0)"
            class="wheel-dial__center"
            text-anchor="middle"
            dominant-baseline="middle"
            :font-size="size * 0.11"
        >{{ centerLabel }}</text>
        <text
            v-if="centerSub"
            :x="c"
            :y="c + size * 0.08"
            class="wheel-dial__center-sub"
            text-anchor="middle"
            dominant-baseline="middle"
            :font-size="size * 0.055"
        >{{ centerSub }}</text>
    </svg>
</template>

<script setup lang="ts">
import {computed} from 'vue';
import {CLOCK_WHEEL_HOUR_SECONDS, formatClockWheelPosition} from '~/functions/clockWheelPosition.ts';
import {clockWheelTypeColor} from '~/functions/clockWheelTypeColors.ts';

export interface WheelDialSlot {
    position_seconds: number;
    type: string;
    label?: string;
}

const props = withDefaults(defineProps<{
    slots: WheelDialSlot[];
    size?: number;
    showTicks?: boolean;
    centerLabel?: string;
    centerSub?: string;
    selectedIndex?: number | null;
    ariaLabel?: string;
}>(), {
    size: 280,
    showTicks: true,
    centerLabel: '',
    centerSub: '',
    selectedIndex: null,
    ariaLabel: 'Clock wheel',
});

const emit = defineEmits<{
    (e: 'select', index: number): void;
}>();

const c = computed(() => props.size / 2);
const stroke = computed(() => props.size * (props.showTicks ? 0.13 : 0.18));
const r = computed(() => c.value - stroke.value / 2 - (props.showTicks ? props.size * 0.09 : 1));

const point = (fraction: number, radius: number) => {
    const angle = fraction * 2 * Math.PI - Math.PI / 2;
    return {x: c.value + radius * Math.cos(angle), y: c.value + radius * Math.sin(angle)};
};

const arc = (from: number, to: number): string => {
    // A full-circle arc cannot be drawn in one SVG command.
    const span = Math.min(to - from, 0.9999);
    const a = point(from, r.value);
    const b = point(from + span, r.value);
    const large = span > 0.5 ? 1 : 0;
    return `M ${a.x} ${a.y} A ${r.value} ${r.value} 0 ${large} 1 ${b.x} ${b.y}`;
};

const segments = computed(() => {
    const ordered = props.slots
        .map((slot, index) => ({slot, index}))
        .sort((a, b) => a.slot.position_seconds - b.slot.position_seconds);

    return ordered.map(({slot, index}, i) => {
        const start = slot.position_seconds / CLOCK_WHEEL_HOUR_SECONDS;
        const nextPos = ordered[i + 1]?.slot.position_seconds ?? CLOCK_WHEEL_HOUR_SECONDS;
        const end = Math.max(nextPos, slot.position_seconds + 1) / CLOCK_WHEEL_HOUR_SECONDS;
        // Leave a hairline gap between segments so each one reads as its own slice.
        const gap = ordered.length > 1 ? 0.0025 : 0;

        return {
            index,
            d: arc(start, Math.max(start + 0.001, end - gap)),
            color: clockWheelTypeColor(slot.type),
            title: `${formatClockWheelPosition(slot.position_seconds)} ${slot.label ?? slot.type}`,
        };
    });
});

const ticks = computed(() => {
    const radius = r.value + stroke.value / 2 + props.size * 0.05;
    return [0, 15, 30, 45].map((minute) => {
        const p = point(minute / 60, radius);
        return {label: `:${String(minute).padStart(2, '0')}`, x: p.x, y: p.y};
    });
});
</script>

<style scoped>
.wheel-dial {
    display: block;
    max-width: 100%;
    height: auto;
}

.wheel-dial__track {
    stroke: var(--bs-secondary-bg);
}

.wheel-dial__segment {
    cursor: pointer;
    transition: opacity .15s ease;
}

.wheel-dial__segment:hover {
    opacity: .8;
}

.wheel-dial__segment.is-selected {
    filter: drop-shadow(0 0 4px rgba(0, 0, 0, .45));
}

.wheel-dial__tick {
    fill: var(--bs-secondary-color);
    font-size: 11px;
    font-weight: 600;
}

.wheel-dial__center {
    fill: var(--bs-body-color);
    font-weight: 700;
}

.wheel-dial__center-sub {
    fill: var(--bs-secondary-color);
}
</style>
