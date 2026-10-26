<template>
    <modal
        id="playlist_group_reorder_modal"
        ref="$modal"
        size="lg"
        :title="$gettext('Reorder Playlist Group')"
        hide-footer
    >
        <loading :loading="isLoading">
            <table class="table table-striped sortable mb-0">
                <thead>
                    <tr>
                        <th style="width: 5%">
                            &nbsp;
                        </th>
                        <th style="width: 55%;">
                            {{ $gettext('Playlist') }}
                        </th>
                        <th style="width: 20%;">
                            {{ $gettext('Source') }}
                        </th>
                        <th style="width: 20%;">
                            {{ $gettext('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody ref="$tbody">
                    <tr
                        v-for="(member, index) in members"
                        :key="member.id"
                        class="align-middle"
                    >
                        <td>
                            <icon-bi-grip-vertical class="text-muted" />
                        </td>
                        <td>
                            <span class="typography-subheading">{{ member.name }}</span>
                        </td>
                        <td>
                            <span class="badge text-bg-secondary">
                                <template v-if="member.source === 'songs'">
                                    {{ $gettext('Song-based') }}
                                </template>
                                <template v-else-if="member.source === 'playlists'">
                                    {{ $gettext('Playlist Group') }}
                                </template>
                                <template v-else-if="member.source === 'remote_url'">
                                    {{ $gettext('Remote URL') }}
                                </template>
                                <template v-else>
                                    {{ member.source }}
                                </template>
                            </span>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button
                                    v-if="index + 1 < members.length"
                                    type="button"
                                    class="btn btn-secondary"
                                    :title="$gettext('Move to Bottom')"
                                    @click.prevent="moveTo(index, members.length - 1)"
                                >
                                    <icon-bi-chevron-bar-down />
                                </button>
                                <button
                                    v-if="index + 1 < members.length"
                                    type="button"
                                    class="btn btn-primary"
                                    :title="$gettext('Move Down')"
                                    @click.prevent="moveTo(index, index + 1)"
                                >
                                    <icon-bi-chevron-down />
                                </button>
                                <button
                                    v-if="index > 0"
                                    type="button"
                                    class="btn btn-primary"
                                    :title="$gettext('Move Up')"
                                    @click.prevent="moveTo(index, index - 1)"
                                >
                                    <icon-bi-chevron-up />
                                </button>
                                <button
                                    v-if="index > 0"
                                    type="button"
                                    class="btn btn-secondary"
                                    :title="$gettext('Move to Top')"
                                    @click.prevent="moveTo(index, 0)"
                                >
                                    <icon-bi-chevron-bar-up />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </loading>
    </modal>
</template>

<script setup lang="ts">
import {ref, useTemplateRef} from "vue";
import {useDraggable} from "vue-draggable-plus";
import Loading from "~/components/Common/Loading.vue";
import Modal from "~/components/Common/Modal.vue";
import {useNotify} from "~/components/Common/Toasts/useNotify.ts";
import {useHasModal} from "~/functions/useHasModal.ts";
import {useAxios} from "~/vendor/axios";
import {useTranslate} from "~/vendor/gettext";
import IconBiChevronBarDown from "~icons/bi/chevron-bar-down";
import IconBiChevronBarUp from "~icons/bi/chevron-bar-up";
import IconBiChevronDown from "~icons/bi/chevron-down";
import IconBiChevronUp from "~icons/bi/chevron-up";
import IconBiGripVertical from "~icons/bi/grip-vertical";

// A member as returned by the group members endpoint (GetGroupMembersAction).
interface GroupMember {
    id: number;
    name: string;
    source: string;
    weight: number;
    consecutive_plays: number;
    play_full_cycle: boolean;
    allowed_requests: string;
}

const emit = defineEmits<(e: "relist") => void>();

const membersUrl = ref<string | null>(null);
const members = ref<GroupMember[]>([]);
const isLoading = ref(false);

const $tbody = useTemplateRef("$tbody");
const $modal = useTemplateRef("$modal");
const {show} = useHasModal($modal);

const {axios} = useAxios();
const {notifySuccess} = useNotify();
const {$gettext} = useTranslate();

let draggableReady = false;

const open = async (url: string) => {
    membersUrl.value = url;
    members.value = [];
    show();

    isLoading.value = true;
    try {
        const {data} = await axios.get<GroupMember[]>(url);
        members.value = [...data].sort((a, b) => a.weight - b.weight);
    } finally {
        isLoading.value = false;
    }

    if (!draggableReady) {
        draggableReady = true;
        useDraggable($tbody, members, {
            onEnd() {
                void save();
            },
        });
    }
};

// The members endpoint replaces the whole member list, so every member's
// settings are sent back unchanged; only the weights (order) change.
const save = async () => {
    if (!membersUrl.value) {
        return;
    }

    await axios.put(membersUrl.value, {
        members: members.value.map((member, index) => ({
            id: member.id,
            weight: index + 1,
            consecutive_plays: member.consecutive_plays ?? 0,
            play_full_cycle: member.play_full_cycle ?? false,
            allowed_requests: member.allowed_requests ?? 'any',
        })),
    });

    notifySuccess($gettext("Playlist group order set."));
    emit("relist");
};

const moveTo = (from: number, to: number) => {
    const item = members.value.splice(from, 1)[0];
    members.value.splice(to, 0, item);
    void save();
};

defineExpose({
    open,
});
</script>

<style lang="scss">
table.sortable {
    cursor: pointer;
}
</style>
