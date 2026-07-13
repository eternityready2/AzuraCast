export type MediaTypeValue = 'music' | 'talk' | 'legal_id' | 'id' | 'promo' | 'ad';

/** Canonical list — keep in sync with {@see ClockWheelSlotTypes} on the backend. */
export const MEDIA_TYPE_VALUES: readonly MediaTypeValue[] = [
    'music',
    'talk',
    'legal_id',
    'id',
    'promo',
    'ad',
] as const;

/** Types shown in Music Files classify / edit UI (legacy legal_id merged into id). */
export const MEDIA_TYPE_UI_VALUES: readonly MediaTypeValue[] = [
    'music',
    'talk',
    'id',
    'promo',
    'ad',
] as const;

export type MediaTypeOption = {
    value: MediaTypeValue;
    label: string;
};

export const normalizeMediaTypeForEditor = (type: string | null | undefined): MediaTypeValue => {
    if (type === 'legal_id') {
        return 'id';
    }

    return isMediaTypeValue(type) ? type : 'music';
};

export const getMediaTypeOptions = ($gettext: (msg: string) => string): MediaTypeOption[] => [
    {
        value: 'music',
        label: $gettext('Music (music and copyrighted material)'),
    },
    {
        value: 'talk',
        label: $gettext('Talk (sermons, speeches, and live recordings)'),
    },
    {
        value: 'id',
        label: $gettext('ID (station identification, sweepers, and top-of-hour IDs)'),
    },
    {
        value: 'promo',
        label: $gettext('Promo (station promotion that is not considered an ID)'),
    },
    {
        value: 'ad',
        label: $gettext('Ad (advert replacement files)'),
    },
];

export const isMediaTypeValue = (type: string | null | undefined): type is MediaTypeValue =>
    MEDIA_TYPE_VALUES.includes(type as MediaTypeValue);

export const formatMediaType = (
    type: string | null | undefined,
    $gettext: (msg: string) => string,
): string => {
    const normalized = normalizeMediaTypeForEditor(type);
    const match = getMediaTypeOptions($gettext).find((opt) => opt.value === normalized);
    if (match) {
        return match.label.split(' (')[0];
    }

    if (!type) {
        return '';
    }

    return type.charAt(0).toUpperCase() + type.slice(1);
};

export const formatMediaCategory = (
    categoryId: number | null | undefined,
    categoryName: string | null | undefined,
    categories: {id: number; name: string}[],
): string => {
    if (categoryName) {
        return categoryName;
    }

    if (categoryId == null) {
        return '';
    }

    return categories.find((c) => c.id === categoryId)?.name ?? '';
};
