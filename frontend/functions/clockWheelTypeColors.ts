import {normalizeMediaTypeForEditor} from '~/functions/mediaTypes.ts';

/** One colour per content type, shared by the dial, legend and distribution bars. */
const TYPE_COLORS: Record<string, string> = {
    music: 'var(--bs-primary)',
    talk: 'var(--bs-warning)',
    id: 'var(--bs-info)',
    promo: '#d63ee0',
    ad: 'var(--bs-danger)',
};

export function clockWheelTypeColor(type: string | null | undefined): string {
    return TYPE_COLORS[normalizeMediaTypeForEditor(type)] ?? 'var(--bs-secondary)';
}
