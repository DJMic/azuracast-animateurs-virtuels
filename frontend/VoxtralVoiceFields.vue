<template>
    <div class="row g-3">
        <div class="col-12">
            <div
                class="btn-group"
                role="group"
                aria-label="Origine de la voix Voxtral"
            >
                <input
                    :id="`${idPrefix}-mode-id`"
                    v-model="voice.mode"
                    type="radio"
                    class="btn-check"
                    value="id"
                >
                <label
                    class="btn btn-outline-secondary"
                    :for="`${idPrefix}-mode-id`"
                >Voix Mistral</label>
                <input
                    :id="`${idPrefix}-mode-sample`"
                    v-model="voice.mode"
                    type="radio"
                    class="btn-check"
                    value="sample"
                >
                <label
                    class="btn btn-outline-secondary"
                    :for="`${idPrefix}-mode-sample`"
                >Voix clonée depuis un fichier</label>
            </div>
        </div>

        <template v-if="voice.mode === 'id'">
            <div class="col-md-8">
                <label
                    class="form-label"
                    :for="`${idPrefix}-voice`"
                >Voix</label>
                <div class="input-group">
                    <input
                        :id="`${idPrefix}-voice`"
                        v-model.trim="voice.voice_id"
                        class="form-control font-monospace"
                        :list="`${idPrefix}-voices`"
                        placeholder="Ex. voice_592"
                        autocomplete="off"
                    >
                    <button
                        type="button"
                        class="btn btn-secondary"
                        :disabled="busy !== ''"
                        @click="loadVoices"
                    >
                        Charger les voix
                    </button>
                </div>
                <datalist :id="`${idPrefix}-voices`">
                    <option
                        v-for="v in voiceList"
                        :key="v.id"
                        :value="v.id"
                    >
                        {{ v.name }}
                    </option>
                </datalist>
                <div class="form-text">
                    <template v-if="currentName">
                        {{ currentName }}.
                    </template>
                    Voix françaises de Mistral : Marie (voice_592), Manon (voice_3137), Léa (voice_589),
                    Camille (voice_3141). Une voix clonée dans Mistral AI Studio s'utilise par son identifiant ;
                    elle y est conservée 30 jours par défaut (jusqu'à un an si vous le réglez à sa création).
                </div>
            </div>
        </template>

        <template v-else>
            <div class="col-md-8">
                <MediaPicker
                    :id="`${idPrefix}-sample`"
                    v-model="voice.sample_media"
                    label="Enregistrement de référence (fichier des Médias)"
                    :api="api"
                    empty-label="Retirer le fichier"
                />
                <div class="form-text">
                    Un enregistrement propre de la voix à reproduire, sans musique ni bruit de fond : une seule personne
                    qui parle, dans le ton voulu à l'antenne. Il est envoyé à Mistral à chaque génération (rien n'est
                    conservé chez Mistral, la voix ne peut donc pas expirer). N'utilisez que la voix d'une personne qui
                    vous a donné son accord.
                </div>
            </div>
            <div class="col-md-2 col-6">
                <label
                    class="form-label"
                    :for="`${idPrefix}-start`"
                >À partir de (s)</label>
                <input
                    :id="`${idPrefix}-start`"
                    v-model.number="voice.sample_start"
                    type="number"
                    min="0"
                    step="0.5"
                    class="form-control"
                >
            </div>
            <div class="col-md-2 col-6">
                <label
                    class="form-label"
                    :for="`${idPrefix}-seconds`"
                >Durée (s)</label>
                <input
                    :id="`${idPrefix}-seconds`"
                    v-model.number="voice.sample_seconds"
                    type="number"
                    min="3"
                    max="30"
                    class="form-control"
                >
            </div>
        </template>

        <div class="col-md-8">
            <label
                class="form-label d-flex justify-content-between"
                :for="`${idPrefix}-expr`"
            >
                <span>Expressivité</span>
                <strong>{{ Number(voice.expressiveness) >= 1 ? Number(voice.expressiveness).toFixed(1) : 'par défaut' }}</strong>
            </label>
            <input
                :id="`${idPrefix}-expr`"
                v-model.number="voice.expressiveness"
                type="range"
                class="form-range"
                min="0"
                max="5"
                step="0.5"
            >
            <div class="form-text">
                Tout à gauche : réglage par défaut de Mistral. De 1 (posé, très fidèle à la voix) à 5 (très expressif,
                mais moins stable et moins fidèle).
            </div>
        </div>
        <div class="col-md-4 d-flex flex-column justify-content-end">
            <button
                type="button"
                class="btn btn-outline-primary"
                :disabled="busy !== ''"
                @click="tryVoice"
            >
                {{ busy === 'try' ? 'Génération…' : 'Écouter un essai' }}
            </button>
        </div>
        <div
            v-if="message.text || audio"
            class="col-12"
        >
            <div
                v-if="message.text"
                class="form-text mt-0"
                :class="message.type === 'error' ? 'text-danger' : ''"
            >
                {{ message.text }}
            </div>
            <audio
                v-if="audio"
                :src="audio"
                controls
                autoplay
                class="w-100 mt-1"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from "vue";
import MediaPicker from "./MediaPicker.vue";
import { VOXTRAL_DEFAULT, VOXTRAL_PRESETS } from "./helpers.js";

const props = defineProps({
    // The voxtral settings of the host or chronicle (edited in place).
    voice: { type: Object, required: true },
    api: { type: Object, required: true },
    idPrefix: { type: String, required: true },
});

for (const [key, value] of Object.entries(VOXTRAL_DEFAULT)) {
    if (props.voice[key] === undefined || props.voice[key] === null) props.voice[key] = value;
}

const loaded = ref([]);
const busy = ref("");
const message = ref({ type: "", text: "" });
const audio = ref("");

const voiceList = computed(() => (loaded.value.length ? loaded.value : VOXTRAL_PRESETS));
const currentName = computed(() => voiceList.value.find((v) => v.id === props.voice.voice_id)?.name || "");

async function loadVoices() {
    busy.value = "voices";
    message.value = { type: "", text: "Chargement des voix…" };
    try {
        const result = await props.api.checkVoxtral("");
        loaded.value = result.voices;
        message.value = { type: "", text: `${result.message} Choisissez-en une dans la liste du champ « Voix ».` };
    } catch (e) {
        message.value = { type: "error", text: e.message };
    } finally {
        busy.value = "";
    }
}

async function tryVoice() {
    busy.value = "try";
    audio.value = "";
    message.value = { type: "", text: "Génération de l'essai…" };
    try {
        const result = await props.api.tryVoxtral({ ...props.voice });
        audio.value = result.audio;
        message.value = { type: "", text: result.message };
    } catch (e) {
        message.value = { type: "error", text: e.message };
    } finally {
        busy.value = "";
    }
}
</script>
