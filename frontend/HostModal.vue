<template>
    <div
        class="modal fade show d-block"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby="av-host-title"
    >
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form
                class="modal-content"
                novalidate
                @submit.prevent="submit"
            >
                <div class="modal-header">
                    <h1
                        id="av-host-title"
                        class="modal-title fs-5"
                    >
                        {{ isNew ? 'Ajouter un animateur' : `Modifier « ${originalName} »` }}
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
                                    v-if="t.key === 'prompts'"
                                    class="badge text-bg-secondary ms-1"
                                >{{ form.prompts.length }}</span>
                                <span
                                    v-if="t.key === 'slots'"
                                    class="badge text-bg-secondary ms-1"
                                >{{ form.slots.length }}</span>
                            </button>
                        </li>
                    </ul>

                    <!-- Général -->
                    <div v-show="tab === 'general'">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-h-name"
                                >Nom de l'animateur</label>
                                <input
                                    id="av-h-name"
                                    v-model="form.name"
                                    class="form-control"
                                    maxlength="80"
                                    required
                                    placeholder="Ex. Léa"
                                >
                                <div class="form-text">
                                    Utilisé dans les textes avec {animateur}.
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input
                                        id="av-h-enabled"
                                        v-model="form.enabled"
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                    >
                                    <label
                                        class="form-check-label"
                                        for="av-h-enabled"
                                    >Animateur actif</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-h-provider"
                                >Service de voix</label>
                                <select
                                    id="av-h-provider"
                                    v-model="form.provider"
                                    class="form-select"
                                >
                                    <option value="elevenlabs">
                                        ElevenLabs
                                    </option>
                                    <option value="voxtral">
                                        Mistral Voxtral
                                    </option>
                                </select>
                                <div
                                    v-if="form.provider === 'voxtral' && !settings.voxtral.api_key"
                                    class="form-text text-warning-emphasis"
                                >
                                    Renseignez et enregistrez d'abord la clé Mistral dans l'onglet « Services IA ».
                                </div>
                                <div
                                    v-else
                                    class="form-text"
                                >
                                    Changer de service change la voix : les clips de la bibliothèque enregistrés avec
                                    l'autre voix ne sont plus réutilisés.
                                </div>
                            </div>
                            <div
                                v-if="form.provider === 'voxtral'"
                                class="col-12"
                            >
                                <VoxtralVoiceFields
                                    :voice="form.voxtral"
                                    :api="api"
                                    id-prefix="av-h-vx"
                                />
                            </div>
                            <div
                                v-if="form.provider !== 'voxtral'"
                                class="col-md-6"
                            >
                                <label
                                    class="form-label"
                                    for="av-h-account"
                                >Compte ElevenLabs</label>
                                <select
                                    id="av-h-account"
                                    v-model="form.account_id"
                                    class="form-select"
                                    :disabled="accounts.length === 0"
                                >
                                    <option
                                        v-for="a in accounts"
                                        :key="a.id || a.name"
                                        :value="a.id"
                                    >
                                        {{ a.name }}
                                    </option>
                                </select>
                                <div class="form-text">
                                    <template v-if="accounts.length === 0">
                                        Ajoutez d'abord un compte dans l'onglet « Services IA » et enregistrez.
                                    </template>
                                    <template v-else>
                                        La voix doit appartenir à ce compte. Ses crédits sont décomptés de ce compte.
                                    </template>
                                </div>
                            </div>
                            <div
                                v-if="form.provider !== 'voxtral'"
                                class="col-md-6"
                            >
                                <label
                                    class="form-label"
                                    for="av-h-voice"
                                >Voice ID ElevenLabs</label>
                                <div class="input-group">
                                    <input
                                        id="av-h-voice"
                                        v-model.trim="form.voice_id"
                                        class="form-control font-monospace"
                                        placeholder="Ex. 21m00Tcm4TlvDq8ikWAM"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        :disabled="checking || !form.voice_id"
                                        @click="checkVoice"
                                    >
                                        Vérifier
                                    </button>
                                </div>
                                <div
                                    class="form-text"
                                    :class="voiceMessage.type === 'error' ? 'text-danger' : (voiceMessage.type === 'ok' ? 'text-success' : '')"
                                >
                                    {{ voiceMessage.text || 'Dans ElevenLabs : My Voices › la voix › « ID ».' }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-h-seconds"
                                >Durée de parole visée (secondes)</label>
                                <input
                                    id="av-h-seconds"
                                    v-model.number="form.target_seconds"
                                    type="number"
                                    min="3"
                                    max="180"
                                    class="form-control"
                                >
                                <div class="form-text">
                                    Environ {{ Math.round(form.target_seconds * 2.5) }} mots. La durée réelle varie un peu.
                                </div>
                            </div>
                            <div class="col-12">
                                <label
                                    class="form-label"
                                    for="av-h-perso"
                                >Personnalité et style</label>
                                <textarea
                                    id="av-h-perso"
                                    v-model="form.personality"
                                    class="form-control"
                                    rows="5"
                                    placeholder="Ex. Léa, 30 ans, anime la matinale. Énergique, souriante, tutoie les auditeurs, aime glisser une petite anecdote sur les artistes."
                                />
                                <div class="form-text">
                                    Décrit la façon de parler de cet animateur. Ajouté aux consignes générales de la station.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label
                                    class="form-label"
                                    for="av-h-bed"
                                >Fond musical de cet animateur</label>
                                <select
                                    id="av-h-bed"
                                    v-model="form.bed_playlist_id"
                                    class="form-select"
                                >
                                    <option :value="null">
                                        Celui de la station (onglet « Fond musical et son »)
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
                        </div>
                    </div>

                    <!-- Voix -->
                    <div
                        v-if="tab === 'voice' && form.provider === 'voxtral'"
                        class="alert alert-info"
                    >
                        Cet animateur parle avec Mistral Voxtral : sa voix et son expressivité se règlent dans l'onglet
                        « Animateur ». Les réglages ci-dessous ne servent qu'avec ElevenLabs.
                    </div>
                    <div v-show="tab === 'voice'">
                        <p class="text-body-secondary">
                            Ces réglages remplacent ceux de la voix dans ElevenLabs, pour cet animateur uniquement.
                        </p>
                        <div class="row g-4">
                            <div
                                v-for="s in sliders"
                                :key="s.key"
                                class="col-md-6"
                            >
                                <label
                                    class="form-label d-flex justify-content-between"
                                    :for="`av-v-${s.key}`"
                                >
                                    <span>{{ s.label }}</span>
                                    <strong>{{ Number(form.voice[s.key]).toFixed(2) }}</strong>
                                </label>
                                <input
                                    :id="`av-v-${s.key}`"
                                    v-model.number="form.voice[s.key]"
                                    type="range"
                                    class="form-range"
                                    :min="s.min"
                                    :max="s.max"
                                    step="0.05"
                                >
                                <div class="form-text">
                                    {{ s.help }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input
                                        id="av-v-boost"
                                        v-model="form.voice.speaker_boost"
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                    >
                                    <label
                                        class="form-check-label"
                                        for="av-v-boost"
                                    >Speaker boost (voix plus fidèle)</label>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="/^eleven_v[34]/.test(settings.elevenlabs.model_id)"
                            class="alert alert-info mt-3 mb-0"
                        >
                            Avec Eleven v3 et v4, les indications de jeu entre crochets comme [warmly] ou [chuckles] ne
                            sont pas lues mais interprétées comme des effets de voix (option « Écriture radio »).
                            <template v-if="settings.elevenlabs.model_id === 'eleven_v3'">
                                Avec v3, la stabilité est arrondie à 0 (créatif), 0,5 (naturel) ou 1 (robuste).
                            </template>
                            Une stabilité plus basse donne plus de relief à la voix, mais des résultats plus variables.
                        </div>
                    </div>

                    <!-- Prompts -->
                    <div v-show="tab === 'prompts'">
                        <p class="text-body-secondary">
                            Pour chaque intervention, un prompt actif est tiré au hasard parmi ceux qui conviennent
                            (entre deux morceaux ou heure fixe). Variables :
                            <code
                                v-for="(label, name) in variables"
                                :key="name"
                                class="me-1"
                                :title="label"
                            >{{ '{' + name + '}' }}</code>
                        </p>

                        <div
                            v-for="(prompt, index) in form.prompts"
                            :key="prompt.id || `new-${index}`"
                            class="card mb-3"
                        >
                            <div class="card-body">
                                <div class="row g-2 align-items-end mb-2">
                                    <div class="col-md-4">
                                        <label
                                            class="form-label"
                                            :for="`av-p-name-${index}`"
                                        >Nom</label>
                                        <input
                                            :id="`av-p-name-${index}`"
                                            v-model="prompt.name"
                                            class="form-control form-control-sm"
                                        >
                                    </div>
                                    <div class="col-md-4">
                                        <label
                                            class="form-label"
                                            :for="`av-p-usage-${index}`"
                                        >Utilisé pour</label>
                                        <select
                                            :id="`av-p-usage-${index}`"
                                            v-model="prompt.usage"
                                            class="form-select form-select-sm"
                                        >
                                            <option
                                                v-for="u in usages"
                                                :key="u.value"
                                                :value="u.value"
                                            >
                                                {{ u.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex gap-2 justify-content-md-end align-items-center">
                                        <div class="form-check form-switch mb-0">
                                            <input
                                                :id="`av-p-enabled-${index}`"
                                                v-model="prompt.enabled"
                                                class="form-check-input"
                                                type="checkbox"
                                                role="switch"
                                            >
                                            <label
                                                class="form-check-label"
                                                :for="`av-p-enabled-${index}`"
                                            >Actif</label>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-secondary"
                                            :disabled="previewing"
                                            @click="preview(index)"
                                        >
                                            Aperçu
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            @click="form.prompts.splice(index, 1)"
                                        >
                                            Supprimer
                                        </button>
                                    </div>
                                </div>
                                <div
                                    v-if="imposedAt(prompt.id).length"
                                    class="alert alert-info py-1 px-2 small mb-2"
                                >
                                    Imposé à {{ imposedAt(prompt.id).join(', ') }} (onglet « Tranches horaires ») : il ne
                                    sert qu'à ces moments-là et n'est jamais tiré au hasard. Son usage ci-dessus ne compte
                                    pas.
                                </div>
                                <textarea
                                    v-model="prompt.text"
                                    class="form-control"
                                    rows="3"
                                    :aria-label="`Texte du prompt ${prompt.name}`"
                                />
                                <div class="form-check mt-2 mb-0">
                                    <input
                                        :id="`av-p-timeless-${index}`"
                                        v-model="prompt.timeless"
                                        class="form-check-input"
                                        type="checkbox"
                                    >
                                    <label
                                        class="form-check-label"
                                        :for="`av-p-timeless-${index}`"
                                    >Intemporel : les voix obtenues vont dans la bibliothèque et peuvent être rediffusées</label>
                                </div>
                                <div
                                    v-if="prompt.timeless && contextVariables(prompt.text).length"
                                    class="text-danger small mt-1"
                                >
                                    Un prompt intemporel ne peut pas utiliser
                                    {{ contextVariables(prompt.text).map((v) => '{' + v + '}').join(', ') }} :
                                    ces informations changent à chaque diffusion.
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="addPrompt"
                        >
                            Ajouter un prompt
                        </button>

                        <div
                            v-if="previewMessages"
                            class="mt-3"
                        >
                            <h3 class="fs-6">
                                Ce qui sera envoyé à DeepSeek (exemple avec les morceaux actuels)
                            </h3>
                            <div
                                v-for="(m, i) in previewMessages"
                                :key="i"
                                class="mb-2"
                            >
                                <div class="small fw-semibold">
                                    {{ m.role === 'system' ? 'Consignes (système)' : 'Demande' }}
                                </div>
                                <div class="av-prompt-preview border rounded p-2 bg-body-tertiary">
                                    {{ m.content }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bibliothèque -->
                    <div v-show="tab === 'library'">
                        <p class="text-body-secondary">
                            Chaque voix obtenue avec un prompt <strong>intemporel</strong> est gardée dans la bibliothèque
                            de l'animateur, si son texte ne mentionne ni moment ni morceau (vérification automatique).
                            Une intervention « entre deux morceaux » peut alors reprendre une de ces voix sur un nouveau
                            fond musical, sans appeler DeepSeek ni ElevenLabs. Vous pouvez écouter et supprimer les
                            clips dans l'onglet « Bibliothèque » de la page.
                        </p>
                        <div
                            v-if="!form.prompts.some((p) => p.timeless && p.enabled)"
                            class="alert alert-warning"
                        >
                            Aucun prompt actif n'est marqué « intemporel » : la bibliothèque ne se remplira pas.
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input
                                id="av-l-enabled"
                                v-model="form.library.enabled"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                            >
                            <label
                                class="form-check-label"
                                for="av-l-enabled"
                            >Réutiliser les clips de la bibliothèque</label>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label
                                    class="form-label"
                                    for="av-l-min"
                                >Commencer à partir de (clips)</label>
                                <input
                                    id="av-l-min"
                                    v-model.number="form.library.min_size"
                                    type="number"
                                    min="1"
                                    max="1000"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4">
                                <label
                                    class="form-label"
                                    for="av-l-share"
                                >Part maximale de réutilisation (%)</label>
                                <input
                                    id="av-l-share"
                                    v-model.number="form.library.max_share"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4">
                                <label
                                    class="form-label"
                                    for="av-l-gap"
                                >Délai avant de rediffuser un clip (jours)</label>
                                <input
                                    id="av-l-gap"
                                    v-model.number="form.library.min_gap_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4">
                                <label
                                    class="form-label"
                                    for="av-l-uses"
                                >Diffusions maximum par clip (0 = illimité)</label>
                                <input
                                    id="av-l-uses"
                                    v-model.number="form.library.max_uses"
                                    type="number"
                                    min="0"
                                    max="1000"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4">
                                <label
                                    class="form-label"
                                    for="av-l-size"
                                >Taille maximale (clips)</label>
                                <input
                                    id="av-l-size"
                                    v-model.number="form.library.max_size"
                                    type="number"
                                    min="5"
                                    max="2000"
                                    class="form-control"
                                >
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input
                                        id="av-l-fallback"
                                        v-model="form.library.fallback"
                                        class="form-check-input"
                                        type="checkbox"
                                    >
                                    <label
                                        class="form-check-label"
                                        for="av-l-fallback"
                                    >Secours : utiliser la bibliothèque en cas de panne ou de limite quotidienne atteinte</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Avant {{ form.library.min_size }} clips, toutes les interventions sont nouvelles. Ensuite, la
                            part de réutilisation augmente avec la taille de la bibliothèque, jusqu'à
                            {{ form.library.max_share }} % des interventions « entre deux morceaux » à partir de
                            {{ form.library.min_size * 3 }} clips. Les enchaînements qui citent les morceaux et les heures
                            fixes restent toujours nouveaux. Au-delà de la taille maximale, le clip le plus diffusé laisse
                            sa place. Si vous changez la voix (Voice ID ou réglages), les anciens clips ne sont plus
                            réutilisés.
                        </div>
                    </div>

                    <!-- Tranches horaires -->
                    <div v-show="tab === 'slots'">
                        <p class="text-body-secondary">
                            Pendant une tranche, l'animateur parle toutes les N chansons et/ou à des heures fixes (il
                            prend alors la parole juste après le morceau en cours à cette heure-là). Une heure de fin plus
                            petite que l'heure de début signifie que la tranche passe minuit.
                        </p>
                        <div
                            v-for="(slot, index) in form.slots"
                            :key="slot.id || `new-${index}`"
                            class="card mb-3"
                        >
                            <div class="card-body">
                                <div class="d-flex flex-wrap gap-3 align-items-end">
                                    <div>
                                        <div class="form-label">
                                            Jours
                                        </div>
                                        <div
                                            class="btn-group btn-group-sm av-days"
                                            role="group"
                                        >
                                            <template
                                                v-for="d in days"
                                                :key="d.value"
                                            >
                                                <input
                                                    :id="`av-s-${index}-d${d.value}`"
                                                    v-model="slot.days"
                                                    type="checkbox"
                                                    class="btn-check"
                                                    :value="d.value"
                                                    autocomplete="off"
                                                >
                                                <label
                                                    class="btn btn-outline-primary"
                                                    :for="`av-s-${index}-d${d.value}`"
                                                    :title="d.label"
                                                >{{ d.short }}</label>
                                            </template>
                                        </div>
                                    </div>
                                    <div>
                                        <label
                                            class="form-label"
                                            :for="`av-s-start-${index}`"
                                        >De</label>
                                        <input
                                            :id="`av-s-start-${index}`"
                                            v-model="slot.start"
                                            type="time"
                                            class="form-control form-control-sm"
                                        >
                                    </div>
                                    <div>
                                        <label
                                            class="form-label"
                                            :for="`av-s-end-${index}`"
                                        >À</label>
                                        <input
                                            :id="`av-s-end-${index}`"
                                            v-model="slot.end"
                                            type="time"
                                            class="form-control form-control-sm"
                                        >
                                    </div>
                                    <div style="max-width: 15rem">
                                        <label
                                            class="form-label"
                                            :for="`av-s-n-${index}`"
                                        >Parler toutes les … chansons</label>
                                        <input
                                            :id="`av-s-n-${index}`"
                                            v-model.number="slot.every_n"
                                            type="number"
                                            min="0"
                                            max="100"
                                            class="form-control form-control-sm"
                                        >
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-danger ms-auto"
                                        @click="form.slots.splice(index, 1)"
                                    >
                                        Supprimer la tranche
                                    </button>
                                </div>
                                <div class="form-text mb-2">
                                    0 chanson = uniquement aux heures fixes. Les jingles ne comptent pas.
                                </div>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <span class="small">Heures fixes :</span>
                                    <div
                                        v-for="(time, ti) in slot.times"
                                        :key="ti"
                                        class="input-group input-group-sm"
                                        style="width: auto"
                                    >
                                        <input
                                            v-model="slot.times[ti]"
                                            type="time"
                                            class="form-control"
                                            :aria-label="`Heure fixe ${ti + 1}`"
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-outline-danger"
                                            aria-label="Retirer"
                                            @click="slot.times.splice(ti, 1)"
                                        >
                                            ×
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="slot.times.push(slot.start)"
                                    >
                                        + Ajouter une heure
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        title="Ajoute toutes les heures pile de la tranche"
                                        @click="addHourly(slot)"
                                    >
                                        Toutes les heures pile
                                    </button>
                                </div>

                                <div
                                    v-if="slot.times.length"
                                    class="mt-3"
                                >
                                    <div class="small mb-1">
                                        Prompts imposés à une heure fixe :
                                    </div>
                                    <div
                                        v-for="(rule, ri) in slot.fixed_prompts"
                                        :key="ri"
                                        class="d-flex flex-wrap gap-2 align-items-center mb-2"
                                    >
                                        <select
                                            v-model="rule.time"
                                            class="form-select form-select-sm w-auto"
                                            :aria-label="`Heure du prompt imposé ${ri + 1}`"
                                        >
                                            <option
                                                v-for="t in sortedTimes(slot)"
                                                :key="t"
                                                :value="t"
                                            >
                                                {{ t }}
                                            </option>
                                        </select>
                                        <div
                                            class="btn-group btn-group-sm av-days"
                                            role="group"
                                            :aria-label="`Jours du prompt imposé ${ri + 1}`"
                                        >
                                            <template
                                                v-for="d in days.filter((x) => slot.days.includes(x.value))"
                                                :key="d.value"
                                            >
                                                <input
                                                    :id="`av-s-${index}-r${ri}-d${d.value}`"
                                                    v-model="rule.days"
                                                    type="checkbox"
                                                    class="btn-check"
                                                    :value="d.value"
                                                    autocomplete="off"
                                                >
                                                <label
                                                    class="btn btn-outline-secondary"
                                                    :for="`av-s-${index}-r${ri}-d${d.value}`"
                                                    :title="d.label"
                                                >{{ d.short }}</label>
                                            </template>
                                        </div>
                                        <span class="small text-body-secondary">→</span>
                                        <select
                                            v-model="rule.prompt_id"
                                            class="form-select form-select-sm w-auto"
                                            :aria-label="`Prompt imposé ${ri + 1}`"
                                        >
                                            <option value="">
                                                Choisir un prompt…
                                            </option>
                                            <option
                                                v-for="p in form.prompts.filter((x) => x.enabled && x.text.trim() !== '')"
                                                :key="p.id"
                                                :value="p.id"
                                            >
                                                {{ p.name }}
                                            </option>
                                        </select>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            aria-label="Retirer ce prompt imposé"
                                            @click="slot.fixed_prompts.splice(ri, 1)"
                                        >
                                            ×
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="slot.fixed_prompts.push({ time: sortedTimes(slot)[0], days: [], prompt_id: '' })"
                                    >
                                        + Imposer un prompt
                                    </button>
                                    <div class="form-text">
                                        À cette heure fixe, l'animateur utilise toujours ce prompt, les jours cochés
                                        (aucun jour coché = tous les jours de la tranche). Un prompt imposé est réservé à
                                        ses heures : il n'est jamais tiré au hasard ailleurs. Les autres heures fixes
                                        tirent un prompt au hasard, comme d'habitude.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="addSlot"
                        >
                            Ajouter une tranche horaire
                        </button>
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
import VoxtralVoiceFields from "./VoxtralVoiceFields.vue";
import { DAYS, USAGES, VOXTRAL_DEFAULT, clone, contextVariables } from "./helpers.js";

const props = defineProps({
    api: { type: Object, required: true },
    host: { type: Object, required: true },
    isNew: { type: Boolean, default: false },
    playlists: { type: Array, required: true },
    variables: { type: Object, required: true },
    settings: { type: Object, required: true },
    apply: { type: Function, required: true },
});
const emit = defineEmits(["close"]);

const tabs = [
    { key: "general", label: "Animateur" },
    { key: "voice", label: "Réglages de la voix" },
    { key: "prompts", label: "Prompts" },
    { key: "slots", label: "Tranches horaires" },
    { key: "library", label: "Bibliothèque" },
];
const days = DAYS;
const usages = USAGES;
const sliders = [
    { key: "stability", label: "Stabilité", min: 0, max: 1, help: "Bas : plus expressif mais variable. Haut : plus régulier." },
    { key: "similarity_boost", label: "Fidélité à la voix", min: 0, max: 1, help: "Haut : plus proche de la voix d'origine." },
    { key: "style", label: "Exagération du style", min: 0, max: 1, help: "0 recommandé ; augmente l'emphase mais peut déformer." },
    { key: "speed", label: "Vitesse", min: 0.7, max: 1.2, help: "1 = normal. De 0,7 (lent) à 1,2 (rapide)." },
];

const form = reactive(clone(props.host));
// Hosts saved before version 1.1.0 have no library settings.
form.library = { enabled: false, min_size: 20, max_share: 60, min_gap_days: 3, max_uses: 0, max_size: 200, fallback: true, ...(form.library || {}) };
form.prompts.forEach((p) => { p.timeless = Boolean(p.timeless); });
// Hosts saved before version 1.5.0 always use ElevenLabs.
form.provider = form.provider || "elevenlabs";
form.voxtral = { ...VOXTRAL_DEFAULT, ...(form.voxtral || {}) };
// Saved accounts only (a new account needs to be saved before a host can use it).
const accounts = computed(() => props.settings.elevenlabs.accounts.filter((a) => a.id));
if (!accounts.value.some((a) => a.id === form.account_id)) form.account_id = accounts.value[0]?.id || "";
const originalName = props.host.name;
const tab = ref("general");
const saving = ref(false);
const error = ref("");
const checking = ref(false);
const voiceMessage = ref({ type: "", text: "" });
const previewing = ref(false);
const previewMessages = ref(null);

// Prompt ids are made here, so that a new prompt can be imposed at a fixed time before saving.
const newId = () => Array.from(crypto.getRandomValues(new Uint8Array(6)), (b) => b.toString(16).padStart(2, "0")).join("");
form.prompts.forEach((p) => { if (!p.id) p.id = newId(); });
form.slots.forEach((slot) => {
    slot.fixed_prompts = (slot.fixed_prompts || []).map((r) => ({ ...r, days: [...(r.days || [])] }));
});

// Where a prompt is imposed, e.g. ["Ve 17:00", "Lu–Ve 09:00"] (empty: drawn at random as usual).
function imposedAt(promptId) {
    const labels = [];
    for (const slot of form.slots) {
        for (const rule of slot.fixed_prompts || []) {
            if (!promptId || rule.prompt_id !== promptId || !rule.time) continue;
            const ruleDays = rule.days?.length ? rule.days : slot.days;
            labels.push(`${days.filter((d) => ruleDays.includes(d.value)).map((d) => d.short).join(" ")} ${rule.time}`);
        }
    }
    return labels;
}

function sortedTimes(slot) {
    return [...new Set(slot.times.filter((t) => t))].sort();
}

function addPrompt() {
    form.prompts.push({ id: newId(), name: `Prompt ${form.prompts.length + 1}`, usage: "all", enabled: true, timeless: false, text: "" });
}

function addSlot() {
    form.slots.push({ id: "", days: [1, 2, 3, 4, 5, 6, 7], start: "06:00", end: "10:00", every_n: 4, times: [], fixed_prompts: [] });
}

function addHourly(slot) {
    const toMinutes = (t) => { const [h, m] = t.split(":").map(Number); return h * 60 + m; };
    const start = toMinutes(slot.start);
    const length = (toMinutes(slot.end) - start + 1440) % 1440 || 1440;
    const times = new Set(slot.times);
    for (let m = 0; m < length; m++) {
        const t = (start + m) % 1440;
        if (t % 60 === 0) times.add(`${String(t / 60).padStart(2, "0")}:00`);
    }
    slot.times = [...times];
}

async function checkVoice() {
    checking.value = true;
    voiceMessage.value = { type: "", text: "Vérification…" };
    try {
        const account = accounts.value.find((a) => a.id === form.account_id);
        if (!account) throw new Error("Choisissez d'abord un compte ElevenLabs.");
        const result = await props.api.checkElevenLabs(account.api_key, form.voice_id, account.id);
        voiceMessage.value = { type: "ok", text: result.message };
    } catch (e) {
        voiceMessage.value = { type: "error", text: e.message };
    } finally {
        checking.value = false;
    }
}

async function preview(index) {
    previewing.value = true;
    error.value = "";
    try {
        const result = await props.api.preview({
            host: { ...form, name: form.name || "Animateur" },
            prompt_index: form.prompts.slice(0, index).filter((p) => p.text.trim() !== "").length,
            system_prompt: props.settings.system_prompt,
            language: props.settings.language,
        });
        previewMessages.value = result.messages;
    } catch (e) {
        error.value = e.message;
    } finally {
        previewing.value = false;
    }
}

async function submit() {
    error.value = "";
    if (!form.name.trim()) {
        tab.value = "general";
        error.value = "Indiquez le nom de l'animateur.";
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
