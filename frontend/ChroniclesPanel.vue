<template>
    <div>
        <p class="text-body-secondary">
            Une chronique (infos, agenda, météo, horoscope…) est écrite par DeepSeek à partir de ses sources, lue par
            ElevenLabs, mixée sur un fond musical avec un éventuel habillage, puis enregistrée dans les
            <strong>médias</strong> de la station. Elle remplace la version précédente dans la playlist choisie : la
            diffusion se programme ensuite avec le planificateur de playlists d'AzuraCast. Chaque chronique a son propre
            fond musical (un fichier des médias) et ses propres réglages de son.
        </p>
        <div class="buttons mb-3">
            <button
                type="button"
                class="btn btn-primary"
                :disabled="!defaultChronicle"
                @click="edit(-1)"
            >
                Ajouter une chronique
            </button>
        </div>

        <p
            v-if="settings.chronicles.length === 0"
            class="mb-0"
        >
            Aucune chronique pour l'instant.
        </p>
        <div
            v-else
            class="table-responsive"
        >
            <table class="table table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Chronique</th>
                        <th>Génération</th>
                        <th>Diffusion</th>
                        <th class="text-end">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(chronicle, index) in settings.chronicles"
                        :key="chronicle.id || index"
                    >
                        <td>
                            <h5 class="mb-1">
                                {{ chronicle.name }}
                                <span
                                    v-if="!chronicle.enabled"
                                    class="badge text-bg-secondary ms-1"
                                >Désactivée</span>
                            </h5>
                            <small class="text-body-secondary">
                                Voix : {{ voiceLabel(chronicle) }} · {{ chronicle.sources.length }} source(s) ·
                                environ {{ chronicle.target_seconds }} s
                            </small>
                        </td>
                        <td class="small">
                            {{ describeSchedule(chronicle.schedule) }}
                        </td>
                        <td class="small">
                            <div>{{ playlistName(chronicle.playlist_id) }}</div>
                            <div class="text-body-secondary">
                                <template v-if="files[chronicle.id] && files[chronicle.id].latest">
                                    Dernière : {{ files[chronicle.id].latest.path }}
                                    ({{ formatTimestamp(files[chronicle.id].latest.created_at) }})
                                </template>
                                <template v-else>
                                    Dossier : {{ chronicle.folder }}
                                </template>
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="btn-group btn-group-sm">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    @click="edit(index)"
                                >
                                    Modifier
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="!chronicle.id || running === chronicle.id"
                                    :title="chronicle.id ? '' : 'Enregistrez d\'abord'"
                                    @click="run(chronicle)"
                                >
                                    <span
                                        v-if="running === chronicle.id"
                                        class="spinner-border spinner-border-sm me-1"
                                    />
                                    Générer maintenant
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-danger"
                                    @click="remove(index)"
                                >
                                    Supprimer
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ChronicleModal
            v-if="editing !== null"
            :api="api"
            :chronicle="editing.chronicle"
            :is-new="editing.index < 0"
            :settings="settings"
            :playlists="playlists"
            :default-source="defaultSource"
            :apply="apply"
            @close="editing = null"
        />
    </div>
</template>

<script setup>
import { ref } from "vue";
import ChronicleModal from "./ChronicleModal.vue";
import { clone, describeSchedule, formatTimestamp } from "./helpers.js";

const props = defineProps({
    api: { type: Object, required: true },
    settings: { type: Object, required: true },
    playlists: { type: Array, required: true },
    files: { type: Object, required: true },
    defaultChronicle: { type: Object, default: null },
    defaultSource: { type: Object, default: null },
    save: { type: Function, required: true },
});
const emit = defineEmits(["notice", "history"]);

const editing = ref(null);
const running = ref(null);

function voiceLabel(chronicle) {
    if (chronicle.voice_mode === "host") {
        return props.settings.hosts.find((h) => h.id === chronicle.host_id)?.name || "animateur introuvable";
    }
    return chronicle.voice_name || "voix propre";
}

function playlistName(id) {
    if (!id) return "Aucune playlist (médias seulement)";
    return `Playlist « ${props.playlists.find((p) => p.id === id)?.name || "?"} »`;
}

function edit(index) {
    const chronicle = index < 0
        ? { ...clone(props.defaultChronicle), host_id: props.settings.hosts[0]?.id || "", voice_mode: props.settings.hosts.length ? "host" : "own" }
        : clone(props.settings.chronicles[index]);
    editing.value = { index, chronicle };
}

async function apply(chronicle) {
    const previous = clone(props.settings.chronicles);
    if (editing.value.index < 0) props.settings.chronicles.push(chronicle);
    else props.settings.chronicles[editing.value.index] = chronicle;
    try {
        await props.save();
        emit("notice", { type: "success", text: `Chronique « ${chronicle.name} » enregistrée.` });
        editing.value = null;
    } catch (error) {
        props.settings.chronicles.splice(0, props.settings.chronicles.length, ...previous);
        throw error;
    }
}

async function remove(index) {
    const chronicle = props.settings.chronicles[index];
    if (!window.confirm(`Supprimer la chronique « ${chronicle.name} » ?\n\nLes fichiers déjà enregistrés dans les médias `
        + "sont conservés : supprimez-les dans AzuraCast si besoin.")) return;
    const previous = clone(props.settings.chronicles);
    props.settings.chronicles.splice(index, 1);
    try {
        await props.save();
        emit("notice", { type: "success", text: `Chronique « ${chronicle.name} » supprimée.` });
    } catch (error) {
        props.settings.chronicles.splice(0, props.settings.chronicles.length, ...previous);
        emit("notice", { type: "error", text: error.message });
    }
}

async function run(chronicle) {
    running.value = chronicle.id;
    try {
        const result = await props.api.runChronicle(chronicle.id);
        emit("notice", { type: "success", text: result.message });
        emit("history");
    } catch (error) {
        emit("notice", { type: "error", text: error.message });
    } finally {
        running.value = null;
    }
}
</script>
