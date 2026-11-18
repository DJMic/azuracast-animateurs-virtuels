<template>
    <div
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="av-c-title"
    >
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form
                class="modal-content"
                novalidate
                @submit.prevent="submit"
            >
                <div class="modal-header">
                    <h1
                        id="av-c-title"
                        class="modal-title fs-5"
                    >
                        {{ isNew ? 'Ajouter une chronique' : `Modifier « ${originalName} »` }}
                    </h1>
                    <button
                        type="button"
                        class="btn-close"
                        aria-label="Fermer"
                        @click="emit('close')"
                    />
                </div>

                <div class="modal-body">
                    <ul
                        class="nav nav-tabs mb-3"
                        role="tablist"
                    >
                        <li
                            v-for="t in tabs"
                            :key="t.key"
                            class="nav-item"
                            role="presentation"
                        >
                            <button
                                type="button"
                                class="nav-link"
                                :class="{ active: tab === t.key }"
                                role="tab"
                                :aria-selected="tab === t.key"
                                @click="tab = t.key"
                            >
                                {{ t.label }}
                                <span
                                    v-if="t.key === 'sources'"
                                    class="badge text-bg-secondary ms-1"
                                >{{ form.sources.length }}</span>
                            </button>
                        </li>
                    </ul>

                    <!-- Chronique et voix -->
                    <div v-show="tab === 'general'">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-name"
                                >Nom de la chronique</label>
                                <input
                                    id="av-c-name"
                                    v-model="form.name"
                                    class="form-control"
                                    maxlength="80"
                                    placeholder="Ex. Infos régionales, Météo, Horoscope"
                                    @input="syncFolder"
                                >
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input
                                        id="av-c-enabled"
                                        v-model="form.enabled"
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                    >
                                    <label
                                        class="form-check-label"
                                        for="av-c-enabled"
                                    >Chronique active (générée aux heures prévues)</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-voicemode"
                                >Voix</label>
                                <select
                                    id="av-c-voicemode"
                                    v-model="form.voice_mode"
                                    class="form-select"
                                >
                                    <option value="host">
                                        La voix d'un animateur
                                    </option>
                                    <option value="own">
                                        Une voix propre à cette chronique
                                    </option>
                                </select>
                            </div>
                            <div
                                v-if="form.voice_mode === 'host'"
                                class="col-md-6"
                            >
                                <label
                                    class="form-label"
                                    for="av-c-host"
                                >Animateur</label>
                                <select
                                    id="av-c-host"
                                    v-model="form.host_id"
                                    class="form-select"
                                >
                                    <option
                                        v-for="h in hosts"
                                        :key="h.id"
                                        :value="h.id"
                                    >
                                        {{ h.name }}
                                    </option>
                                </select>
                                <div class="form-text">
                                    Voix, réglages, compte ElevenLabs et personnalité de cet animateur.
                                </div>
                            </div>
                            <template v-else>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="av-c-vname"
                                    >Nom du chroniqueur</label>
                                    <input
                                        id="av-c-vname"
                                        v-model="form.voice_name"
                                        class="form-control"
                                        maxlength="80"
                                        placeholder="Utilisé pour {animateur}"
                                    >
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="av-c-account"
                                    >Compte ElevenLabs</label>
                                    <select
                                        id="av-c-account"
                                        v-model="form.account_id"
                                        class="form-select"
                                    >
                                        <option
                                            v-for="a in accounts"
                                            :key="a.id"
                                            :value="a.id"
                                        >
                                            {{ a.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="av-c-voice"
                                    >Voice ID</label>
                                    <div class="input-group">
                                        <input
                                            id="av-c-voice"
                                            v-model.trim="form.voice_id"
                                            class="form-control font-monospace"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="busy === 'voice' || !form.voice_id"
                                            @click="checkVoice"
                                        >
                                            Vérifier
                                        </button>
                                    </div>
                                    <div
                                        v-if="voiceMessage"
                                        class="form-text"
                                    >
                                        {{ voiceMessage }}
                                    </div>
                                </div>
                                <div
                                    v-for="sl in sliders"
                                    :key="sl.key"
                                    class="col-md-3"
                                >
                                    <label
                                        class="form-label d-flex justify-content-between"
                                        :for="`av-c-v-${sl.key}`"
                                    >
                                        <span>{{ sl.label }}</span>
                                        <strong>{{ Number(form.voice[sl.key]).toFixed(2) }}</strong>
                                    </label>
                                    <input
                                        :id="`av-c-v-${sl.key}`"
                                        v-model.number="form.voice[sl.key]"
                                        type="range"
                                        class="form-range"
                                        :min="sl.min"
                                        :max="sl.max"
                                        step="0.05"
                                    >
                                </div>
                            </template>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-seconds"
                                >Durée de parole visée (secondes)</label>
                                <input
                                    id="av-c-seconds"
                                    v-model.number="form.target_seconds"
                                    type="number"
                                    min="10"
                                    max="600"
                                    class="form-control"
                                >
                                <div class="form-text">
                                    Environ {{ Math.round(form.target_seconds * 2.5) }} mots, soit environ
                                    {{ Math.round(form.target_seconds * 15) }} crédits ElevenLabs par génération.
                                </div>
                            </div>
                            <div class="col-12">
                                <label
                                    class="form-label"
                                    for="av-c-style"
                                >Style de la chronique (facultatif)</label>
                                <textarea
                                    id="av-c-style"
                                    v-model="form.style"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Ex. Ton posé et informatif, phrases claires, une touche d'humour en fin de chronique."
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Sources -->
                    <div v-show="tab === 'sources'">
                        <p class="text-body-secondary">
                            Le plugin lit les sources au moment de la génération et ne transmet que leur texte à DeepSeek,
                            qui ne doit rien inventer. Les flux RSS/Atom sont les plus fiables (articles datés, texte
                            propre). Certaines pages web bloquent les robots ou se construisent en JavaScript : utilisez
                            « Tester les sources ». La météo vient d'Open-Meteo (gratuit, réservé à un usage non
                            commercial).
                        </p>
                        <div
                            v-for="(src, index) in form.sources"
                            :key="src.id || `new-${index}`"
                            class="border rounded p-3 mb-2"
                        >
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <label
                                        class="form-label"
                                        :for="`av-s-type-${index}`"
                                    >Type</label>
                                    <select
                                        :id="`av-s-type-${index}`"
                                        v-model="src.type"
                                        class="form-select"
                                    >
                                        <option value="url">
                                            Adresse web / RSS
                                        </option>
                                        <option value="weather">
                                            Météo
                                        </option>
                                    </select>
                                </div>
                                <div
                                    v-if="src.type === 'url'"
                                    class="col-md-6"
                                >
                                    <label
                                        class="form-label"
                                        :for="`av-s-url-${index}`"
                                    >Adresse (page web ou flux RSS)</label>
                                    <input
                                        :id="`av-s-url-${index}`"
                                        v-model.trim="src.url"
                                        type="url"
                                        class="form-control"
                                        placeholder="https://…"
                                    >
                                </div>
                                <div
                                    v-else
                                    class="col-md-6"
                                >
                                    <label
                                        class="form-label"
                                        :for="`av-s-loc-${index}`"
                                    >Ville</label>
                                    <div class="input-group">
                                        <input
                                            :id="`av-s-loc-${index}`"
                                            v-model="src.location"
                                            class="form-control"
                                            placeholder="Ex. Namur"
                                            @input="src.latitude = null; src.longitude = null; src.location_label = ''"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            :disabled="busy === `geo-${index}` || !src.location"
                                            @click="locate(src, index)"
                                        >
                                            Localiser
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        <template v-if="src.location_label">
                                            Lieu retenu : {{ src.location_label }}
                                        </template>
                                        <template v-else>
                                            Le lieu est localisé à l'enregistrement si vous ne cliquez pas sur « Localiser ».
                                        </template>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label
                                        class="form-label"
                                        :for="`av-s-label-${index}`"
                                    >Nom (facultatif)</label>
                                    <input
                                        :id="`av-s-label-${index}`"
                                        v-model="src.label"
                                        class="form-control"
                                        maxlength="80"
                                    >
                                </div>
                                <div class="col-md-1 text-end">
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        aria-label="Retirer la source"
                                        @click="form.sources.splice(index, 1)"
                                    >
                                        ×
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                :disabled="form.sources.length >= 10"
                                @click="addSource"
                            >
                                Ajouter une source
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-primary"
                                :disabled="busy === 'sources' || form.sources.length === 0"
                                @click="testSources"
                            >
                                <span
                                    v-if="busy === 'sources'"
                                    class="spinner-border spinner-border-sm me-1"
                                />
                                Tester les sources
                            </button>
                            <label
                                class="ms-md-3 small"
                                for="av-c-fresh"
                            >Articles RSS des dernières</label>
                            <input
                                id="av-c-fresh"
                                v-model.number="form.freshness_hours"
                                type="number"
                                min="0"
                                max="744"
                                class="form-control form-control-sm"
                                style="width: 6rem"
                            >
                            <span class="small">heures (0 = tous)</span>
                        </div>
                        <div
                            v-for="(r, i) in sourceResults"
                            :key="i"
                            class="card mb-2"
                        >
                            <div class="card-body py-2">
                                <div :class="r.ok ? 'text-success' : 'text-danger'">
                                    {{ r.ok ? '✓' : '✗' }} <strong>{{ r.label }}</strong>
                                    <template v-if="r.ok">
                                        — {{ r.kind === 'rss' ? `flux, ${r.items} article(s)` : (r.kind === 'weather' ? 'météo' : 'page web') }},
                                        {{ r.chars }} caractères
                                    </template>
                                    <template v-else>
                                        — {{ r.error }}
                                    </template>
                                </div>
                                <div
                                    v-if="r.excerpt"
                                    class="av-prompt-preview small text-body-secondary mt-1"
                                >
                                    {{ r.excerpt }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Texte -->
                    <div v-show="tab === 'text'">
                        <label
                            class="form-label"
                            for="av-c-prompt"
                        >Demande à DeepSeek</label>
                        <textarea
                            id="av-c-prompt"
                            v-model="form.prompt"
                            class="form-control"
                            rows="6"
                        />
                        <div class="form-text mb-3">
                            Ce qu'il faut tirer des sources, par exemple : « Présente les trois informations régionales les
                            plus importantes du jour » ou « Annonce les sorties de ce week-end dans la région ».
                            Variables : {chronique}, {animateur}, {station}, {jour}, {date}, {heure}, {moment}, {duree},
                            {mots}. DeepSeek reçoit aussi la date du jour, les règles « n'inventer rien » et, si l'option
                            est active, l'écriture radio.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-mtitle"
                                >Titre du fichier</label>
                                <input
                                    id="av-c-mtitle"
                                    v-model="form.meta_title"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-martist"
                                >Artiste du fichier</label>
                                <input
                                    id="av-c-martist"
                                    v-model="form.meta_artist"
                                    class="form-control"
                                >
                            </div>
                        </div>
                        <div class="form-text">
                            Titre et artiste enregistrés dans le fichier : c'est ce qu'affichent les médias, l'historique
                            et le titre en cours d'AzuraCast pendant la diffusion.
                        </div>
                    </div>

                    <!-- Habillage -->
                    <div v-show="tab === 'sound'">
                        <div class="form-check form-switch mb-2">
                            <input
                                id="av-c-bed"
                                v-model="form.bed_enabled"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                            >
                            <label
                                class="form-check-label"
                                for="av-c-bed"
                            >Fond musical sous la voix</label>
                        </div>
                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="av-c-bedpl"
                            >Playlist du fond musical</label>
                            <select
                                id="av-c-bedpl"
                                v-model="form.bed_playlist_id"
                                class="form-select"
                                :disabled="!form.bed_enabled"
                            >
                                <option :value="null">
                                    Celle de la station (Interventions › Fond musical et son)
                                </option>
                                <option
                                    v-for="p in playlists"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.name }}{{ p.advanced ? ' (Avancée)' : '' }}
                                </option>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <MediaPicker
                                    id="av-c-intro"
                                    v-model="form.intro_media"
                                    :api="api"
                                    label="Jingle d'ouverture (facultatif)"
                                />
                            </div>
                            <div class="col-md-6">
                                <MediaPicker
                                    id="av-c-outro"
                                    v-model="form.outro_media"
                                    :api="api"
                                    label="Jingle de fermeture (facultatif)"
                                />
                            </div>
                        </div>
                        <div class="form-text">
                            Les jingles sont des fichiers de la médiathèque, joués tels quels avant et après la chronique.
                        </div>
                    </div>

                    <!-- Diffusion -->
                    <div v-show="tab === 'publish'">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-playlist"
                                >Playlist de diffusion</label>
                                <select
                                    id="av-c-playlist"
                                    v-model="form.playlist_id"
                                    class="form-select"
                                >
                                    <option :value="null">
                                        Aucune (fichier dans les médias seulement)
                                    </option>
                                    <option
                                        v-for="p in playlists"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.name }}{{ p.enabled ? '' : ' – désactivée' }}
                                    </option>
                                </select>
                                <div class="form-text">
                                    Chaque nouvelle version y est ajoutée et la précédente en est retirée : la playlist
                                    ne contient que la plus récente. Programmez-la avec le planificateur d'AzuraCast.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-folder"
                                >Dossier dans les médias</label>
                                <input
                                    id="av-c-folder"
                                    v-model="form.folder"
                                    class="form-control"
                                    @input="folderEdited = true"
                                >
                                <div class="form-text">
                                    Créé automatiquement s'il n'existe pas. Fichiers nommés
                                    « {{ exampleFile }} ».
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-c-keep"
                                >Supprimer les anciennes versions après (jours)</label>
                                <input
                                    id="av-c-keep"
                                    v-model.number="form.retention_days"
                                    type="number"
                                    min="0"
                                    max="3650"
                                    class="form-control"
                                >
                                <div class="form-text">
                                    0 = ne jamais supprimer. La version la plus récente n'est jamais supprimée.
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-label">
                                    Générer automatiquement
                                </div>
                                <div class="d-flex flex-wrap gap-3 align-items-center">
                                    <div
                                        class="btn-group btn-group-sm av-days"
                                        role="group"
                                    >
                                        <template
                                            v-for="d in days"
                                            :key="d.value"
                                        >
                                            <input
                                                :id="`av-c-d${d.value}`"
                                                v-model="form.schedule.days"
                                                type="checkbox"
                                                class="btn-check"
                                                :value="d.value"
                                                autocomplete="off"
                                            >
                                            <label
                                                class="btn btn-outline-primary"
                                                :for="`av-c-d${d.value}`"
                                                :title="d.label"
                                            >{{ d.short }}</label>
                                        </template>
                                    </div>
                                    <div
                                        v-for="(time, ti) in form.schedule.times"
                                        :key="ti"
                                        class="input-group input-group-sm"
                                        style="width: auto"
                                    >
                                        <input
                                            v-model="form.schedule.times[ti]"
                                            type="time"
                                            class="form-control"
                                            :aria-label="`Heure de génération ${ti + 1}`"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-outline-danger"
                                            aria-label="Retirer"
                                            @click="form.schedule.times.splice(ti, 1)"
                                        >
                                            ×
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="form.schedule.times.push('06:30')"
                                    >
                                        + Ajouter une heure
                                    </button>
                                </div>
                                <div class="form-text">
                                    Prévoyez la génération quelques minutes avant la diffusion programmée : lecture des
                                    sources, écriture, voix et envoi prennent une à deux minutes. Sans heure, la chronique
                                    n'est générée qu'avec le bouton « Générer maintenant ».
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div
                        v-if="error"
                        class="alert alert-danger w-100 mb-2"
                        role="alert"
                    >
                        {{ error }}
                    </div>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="emit('close')"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="saving"
                    >
                        <span
                            v-if="saving"
                            class="spinner-border spinner-border-sm me-1"
                        />
                        Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop fade show" />
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import MediaPicker from "./MediaPicker.vue";
import { DAYS, clone } from "./helpers.js";

const props = defineProps({
    api: { type: Object, required: true },
    chronicle: { type: Object, required: true },
    isNew: { type: Boolean, default: false },
    settings: { type: Object, required: true },
    playlists: { type: Array, required: true },
    defaultSource: { type: Object, default: null },
    apply: { type: Function, required: true },
});
const emit = defineEmits(["close"]);

const tabs = [
    { key: "general", label: "Chronique et voix" },
    { key: "sources", label: "Sources" },
    { key: "text", label: "Texte" },
    { key: "sound", label: "Fond et habillage" },
    { key: "publish", label: "Diffusion et génération" },
];
const days = DAYS;
const sliders = [
    { key: "stability", label: "Stabilité", min: 0, max: 1 },
    { key: "similarity_boost", label: "Fidélité", min: 0, max: 1 },
    { key: "style", label: "Style", min: 0, max: 1 },
    { key: "speed", label: "Vitesse", min: 0.7, max: 1.2 },
];

const form = reactive(clone(props.chronicle));
const originalName = props.chronicle.name;
const tab = ref("general");
const saving = ref(false);
const error = ref("");
const busy = ref(null);
const voiceMessage = ref("");
const sourceResults = ref([]);
const folderEdited = ref(!props.isNew);

const hosts = computed(() => props.settings.hosts.filter((h) => h.id && h.voice_id));
const accounts = computed(() => props.settings.elevenlabs.accounts.filter((a) => a.id));
if (form.voice_mode === "own" && !accounts.value.some((a) => a.id === form.account_id)) {
    form.account_id = accounts.value[0]?.id || "";
}
if (form.voice_mode === "host" && !hosts.value.some((h) => h.id === form.host_id)) {
    form.host_id = hosts.value[0]?.id || "";
}

function slug(text, space) {
    return text.normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^A-Za-z0-9 _-]+/g, "")
        .replace(/[\s_]+/g, " ").trim().slice(0, 60).replaceAll(" ", space);
}

const exampleFile = computed(() => `${slug(form.name || "Chronique", "_") || "Chronique"}_2026-11-18_0630.mp3`);

function syncFolder() {
    if (!folderEdited.value) form.folder = `Chroniques/${slug(form.name, " ") || "Chronique"}`;
}
if (props.isNew) syncFolder();

function addSource() {
    form.sources.push({ ...clone(props.defaultSource || {}), type: "url", url: "" });
}

async function locate(src, index) {
    busy.value = `geo-${index}`;
    try {
        const { place } = await props.api.geocode(src.location);
        src.latitude = place.latitude;
        src.longitude = place.longitude;
        src.location_label = place.label;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = null;
    }
}

async function testSources() {
    busy.value = "sources";
    error.value = "";
    sourceResults.value = [];
    try {
        sourceResults.value = (await props.api.testSources(clone(form))).results;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = null;
    }
}

async function checkVoice() {
    busy.value = "voice";
    voiceMessage.value = "Vérification…";
    try {
        const account = accounts.value.find((a) => a.id === form.account_id);
        if (!account) throw new Error("Choisissez d'abord un compte ElevenLabs.");
        voiceMessage.value = (await props.api.checkElevenLabs(account.api_key, form.voice_id, account.id)).message;
    } catch (e) {
        voiceMessage.value = e.message;
    } finally {
        busy.value = null;
    }
}

async function submit() {
    error.value = "";
    if (!form.name.trim()) {
        tab.value = "general";
        error.value = "Indiquez le nom de la chronique.";
        return;
    }
    saving.value = true;
    try {
        await props.apply(clone(form));
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

function onKey(event) {
    if (event.key === "Escape") emit("close");
}
onMounted(() => {
    document.addEventListener("keydown", onKey);
    document.body.classList.add("modal-open");
});
onBeforeUnmount(() => {
    document.removeEventListener("keydown", onKey);
    document.body.classList.remove("modal-open");
});
</script>
