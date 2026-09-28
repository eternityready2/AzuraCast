<template>
    <div class="d-flex flex-column gap-3">
        <!-- Header card -->
        <section class="card" role="region" aria-labelledby="hdr_assistant">
            <div class="card-header text-bg-primary d-flex align-items-center gap-2">
                <h2 id="hdr_assistant" class="card-title flex-fill my-0 d-flex align-items-center gap-2">
                    <icon :icon="IconIcPsychology" />
                    {{ $gettext('AI Station Assistant') }}
                </h2>
                <button type="button" class="btn btn-sm btn-outline-light" @click="activeTab = activeTab === 'chat' ? 'settings' : 'chat'">
                    <icon :icon="activeTab === 'chat' ? IconIcSettings : IconIcChat" />
                    {{ activeTab === 'chat' ? $gettext('Settings') : $gettext('Chat') }}
                </button>
            </div>

            <!-- SETTINGS PANEL -->
            <div v-if="activeTab === 'settings'" class="card-body">
                <p class="text-muted mb-3">
                    {{ $gettext('Configure an AI provider to power the assistant. Groq is free — sign up at console.groq.com to get an API key.') }}
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ $gettext('Provider') }}</label>
                        <select v-model="settings.provider" class="form-select">
                            <option value="groq">Groq (Free)</option>
                            <option value="openrouter">OpenRouter</option>
                            <option value="ollama">Ollama (Self-hosted)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ $gettext('API Key') }}</label>
                        <input
                            v-model="settings.api_key"
                            type="password"
                            class="form-control"
                            :placeholder="settings.has_key ? $gettext('(saved — paste to replace)') : 'gsk_...'"
                            autocomplete="off"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ $gettext('Model') }}</label>
                        <input
                            v-model="settings.model"
                            type="text"
                            class="form-control"
                            :placeholder="defaultModelPlaceholder"
                        />
                        <small class="text-muted">{{ $gettext('Leave blank for default.') }}</small>
                    </div>
                    <div v-if="settings.provider === 'ollama'" class="col-12">
                        <label class="form-label fw-semibold">{{ $gettext('Ollama Base URL') }}</label>
                        <input v-model="settings.base_url" type="text" class="form-control" placeholder="http://localhost:11434/v1" />
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2 align-items-center">
                    <button class="btn btn-primary" :disabled="savingSettings" @click="saveSettings">
                        <span v-if="savingSettings" class="spinner-border spinner-border-sm me-1" />
                        {{ $gettext('Save Settings') }}
                    </button>
                    <span v-if="saveSuccess" class="text-success fw-semibold">{{ $gettext('Saved!') }}</span>
                </div>
            </div>

            <!-- CHAT PANEL -->
            <div v-else class="d-flex flex-column" style="height: 70vh;">
                <!-- Messages area -->
                <div ref="messagesEl" class="flex-fill overflow-auto p-3 d-flex flex-column gap-3" style="min-height: 0;">
                    <div v-if="messages.length === 0" class="text-center text-muted py-5">
                        <icon :icon="IconIcPsychology" style="font-size: 3rem; opacity: 0.3;" />
                        <p class="mt-2 mb-1 fw-semibold">{{ $gettext('AI Station Assistant') }}</p>
                        <p class="small">{{ $gettext('Ask me anything about your station — what\'s playing, upcoming schedule, playlists, troubleshooting, or programming suggestions.') }}</p>
                        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                            <button
                                v-for="suggestion in quickSuggestions"
                                :key="suggestion"
                                class="btn btn-sm btn-outline-secondary"
                                @click="sendSuggestion(suggestion)"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                    </div>

                    <template v-for="msg in messages" :key="msg.id">
                        <div
                            class="d-flex"
                            :class="msg.role === 'user' ? 'justify-content-end' : 'justify-content-start'"
                        >
                            <div
                                class="rounded-3 px-3 py-2"
                                :class="msg.role === 'user'
                                    ? 'bg-primary text-white'
                                    : 'bg-body-secondary text-body'"
                                style="max-width: 80%; white-space: pre-wrap; word-break: break-word;"
                            >
                                {{ msg.content }}
                            </div>
                        </div>
                    </template>

                    <!-- Loading bubble -->
                    <div v-if="loading" class="d-flex justify-content-start">
                        <div class="bg-body-secondary rounded-3 px-3 py-2">
                            <span class="spinner-border spinner-border-sm me-1" />
                            {{ $gettext('Thinking…') }}
                        </div>
                    </div>

                    <!-- Error -->
                    <div v-if="error" class="alert alert-danger py-2 mb-0">
                        {{ error }}
                    </div>
                </div>

                <!-- Input area -->
                <div class="border-top p-3 d-flex gap-2 align-items-end">
                    <textarea
                        ref="inputEl"
                        v-model="inputText"
                        class="form-control"
                        rows="2"
                        style="resize: none;"
                        :placeholder="$gettext('Ask anything about your station… (Ctrl+Enter to send)')"
                        :disabled="loading"
                        @keydown.enter.ctrl.prevent="sendMessage"
                    />
                    <button
                        class="btn btn-primary"
                        style="white-space: nowrap;"
                        :disabled="loading || !inputText.trim()"
                        @click="sendMessage"
                    >
                        <icon :icon="IconIcSend" />
                        {{ $gettext('Send') }}
                    </button>
                    <button
                        v-if="messages.length > 0"
                        class="btn btn-outline-secondary"
                        :title="$gettext('Clear conversation')"
                        @click="clearConversation"
                    >
                        <icon :icon="IconIcDelete" />
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>

<script setup lang="ts">
import {ref, computed, nextTick, onMounted} from 'vue';
import {useAxios} from '~/vendor/axios';
import {useApiRouter} from '~/functions/useApiRouter.ts';
import {useTranslate} from '~/vendor/gettext.ts';
import IconIcPsychology from '~icons/ic/baseline-psychology';
import IconIcSettings from '~icons/ic/baseline-settings';
import IconIcChat from '~icons/ic/baseline-chat';
import IconIcSend from '~icons/ic/baseline-send';
import IconIcDelete from '~icons/ic/baseline-delete';

const {axios} = useAxios();
const {getStationApiUrl} = useApiRouter();
const {$gettext} = useTranslate();

const settingsUrl = getStationApiUrl('/assistant/settings');
const chatUrl = getStationApiUrl('/assistant/chat');

// ---- Tabs ----
const activeTab = ref<'chat' | 'settings'>('chat');

// ---- Settings ----
const settings = ref({
    provider: 'groq',
    api_key: '',
    has_key: false,
    model: '',
    base_url: '',
});
const savingSettings = ref(false);
const saveSuccess = ref(false);

const defaultModelPlaceholder = computed(() => {
    const map: Record<string, string> = {
        groq: 'llama-3.3-70b-versatile',
        openrouter: 'meta-llama/llama-3.3-70b-instruct:free',
        ollama: 'llama3.2',
    };
    return map[settings.value.provider] ?? 'default';
});

async function loadSettings() {
    try {
        const {data} = await axios.get(settingsUrl.value);
        settings.value.provider = data.provider ?? 'groq';
        settings.value.has_key = data.has_key ?? false;
        settings.value.model = data.model ?? '';
        settings.value.base_url = data.base_url ?? '';
    } catch {
        // ignore
    }
}

async function saveSettings() {
    savingSettings.value = true;
    saveSuccess.value = false;
    try {
        await axios.post(settingsUrl.value, {
            provider: settings.value.provider,
            api_key: settings.value.api_key || undefined,
            model: settings.value.model,
            base_url: settings.value.base_url,
        });
        settings.value.has_key = true;
        settings.value.api_key = '';
        saveSuccess.value = true;
        setTimeout(() => {
            saveSuccess.value = false;
        }, 3000);
    } catch {
        // ignore
    } finally {
        savingSettings.value = false;
    }
}

// ---- Chat ----
interface Message {
    id: number;
    role: 'user' | 'assistant';
    content: string;
}

const messages = ref<Message[]>([]);
const inputText = ref('');
const loading = ref(false);
const error = ref('');
const messagesEl = ref<HTMLElement | null>(null);
const inputEl = ref<HTMLTextAreaElement | null>(null);
let nextId = 1;

const quickSuggestions = [
    "What's on air right now?",
    "Show me the next 10 songs coming up",
    "What played in the last hour?",
    "List all my playlists",
    "Is the station running normally?",
    "Suggest improvements to my morning programming",
];

async function sendMessage() {
    const text = inputText.value.trim();
    if (!text || loading.value) return;

    error.value = '';
    messages.value.push({id: nextId++, role: 'user', content: text});
    inputText.value = '';
    loading.value = true;

    await scrollToBottom();

    try {
        const {data} = await axios.post(chatUrl.value, {
            messages: messages.value.map(m => ({role: m.role, content: m.content})),
        });

        if (data.error) {
            error.value = data.error;
        } else {
            messages.value.push({id: nextId++, role: 'assistant', content: data.content ?? ''});
        }
    } catch (e: any) {
        error.value = e?.response?.data?.error ?? e?.message ?? 'Unknown error';
    } finally {
        loading.value = false;
        await scrollToBottom();
        inputEl.value?.focus();
    }
}

async function sendSuggestion(text: string) {
    inputText.value = text;
    await sendMessage();
}

function clearConversation() {
    messages.value = [];
    error.value = '';
}

async function scrollToBottom() {
    await nextTick();
    if (messagesEl.value) {
        messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
    }
}

onMounted(() => {
    void loadSettings();
});
</script>
