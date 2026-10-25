import {useTranslate} from "~/vendor/gettext";
import {ref, VNode} from "vue";
import {defineStore} from "pinia";
import {FlashLevels} from "~/entities/ApiInterfaces.ts";
import {useAzuraCast} from "~/vendor/azuracast.ts";
import {remove} from "es-toolkit";

type ToastMessage = string | VNode[]

type ToastOptions = {
    title?: string | null,
    variant?: FlashLevels,
}

export type ToastProps = ToastOptions & {
    id: string,
    message?: string,
    slot?: VNode[],
};

/* Composition API BootstrapVue utilities */
export const useNotify = defineStore(
    'global-toasts',
    () => {
        const {notifications} = useAzuraCast();

        const getRandomId = () => Math.random().toString(36);

        const initialToasts: ToastProps[] = notifications.map(
            (row) => {
                return {
                    id: getRandomId(),
                    ...row
                }
            }
        );

        const toasts = ref<ToastProps[]>(initialToasts);

        const {$gettext} = useTranslate();

        const notify = (
            message: ToastMessage,
            options: ToastOptions = {}
        ): void => {
            if (document.hidden) {
                return;
            }

            // Avoid stacking duplicate toasts (e.g. several polling requests failing
            // at once right after a background tab resumes, which would otherwise show
            // the same generic error message multiple times in a row).
            if (!Array.isArray(message)) {
                const isDuplicate = toasts.value.some(
                    (existing) => existing.message === message
                        && existing.variant === options.variant
                );

                if (isDuplicate) {
                    return;
                }
            }

            const toast: ToastProps = {
                id: getRandomId(),
                ...options
            };

            if (Array.isArray(message)) {
                toast.slot = message;
            } else {
                toast.message = message;
            }

            toasts.value.push(toast);
        };

        const notifyError = (
            message?: ToastMessage,
            options: ToastOptions = {}
        ): void => {
            message ??= $gettext('An error occurred and your request could not be completed.');

            const defaults = {
                variant: FlashLevels.Error
            };

            notify(message, {...defaults, ...options});
        };

        const notifySuccess = (
            message?: ToastMessage,
            options: ToastOptions = {}
        ): void => {
            message ??= $gettext('Changes saved.');

            const defaults = {
                variant: FlashLevels.Success
            };

            notify(message, {...defaults, ...options});
        };

        const removeToast = (
            id: string
        ): void => {
            remove(
                toasts.value,
                (toast) => toast.id === id
            );
        }

        return {
            toasts,
            notify,
            notifyError,
            notifySuccess,
            removeToast
        };
    },
);
