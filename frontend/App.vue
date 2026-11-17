<template>
    <div class="animateurs-virtuels-app">
        <div
            v-if="notice"
            class="alert alert-dismissible"
            :class="notice.type === 'error' ? 'alert-danger' : 'alert-success'"
            role="alert"
        >
            {{ notice.text }}
            <button
                type="button"
                class="btn-close"
                aria-label="Fermer"
                @click="notice = null"
            />
        </div>

        <section
            class="card mb-4"
            role="region"
            aria-labelledby="av-title"
        >
            <div class="card-header text-bg-primary d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h2
                    id="av-title"
                    class="card-title mb-0"
                >
                    Animateurs virtuels
                </h2>
                <small
                    v-if="version"
                    class="opacity-75"
                >v{{ version }}</small>
            </div>

            <div
                v-if="loading && !loaded"
                class="card-body"
            >
                <div
                    class="spinner-border spinner-border-sm me-2"
                    role="status"
                />
                Chargement…
            </div>
            <div
                v-else-if="loadError"
                class="card-body"
            >
                <div class="alert alert-danger mb-0">
                    {{ loadError }}
                </div>
            </div>

            <template v-else-if="settings">
                <div class="card-body alert alert-info mb-0 rounded-0">
                    Des animateurs créés par IA prennent la parole entre les morceaux de
                    <strong>{{ station.name }}</strong> : DeepSeek écrit le texte, ElevenLabs le lit, et la voix est
                    mixée sur un fond musical. Les horaires suivent le fuseau horaire de la station
                    (<strong>{{ station.timezone }}</strong>).
                </div>

                <div class="card-body">
                    <div
                        v-for="problem in problems"
                        :key="problem"
                        class="alert alert-warning"
                    >
                        {{ problem }}
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-4">
                        <div class="form-check form-switch fs-5 mb-0">
                            <input
                                id="av-enabled"
                                v-model="settings.enabled"
                                class="form-check-input"
                                type="checkbox"
                                role="switch"
                            >
                            <label
                                class="form-check-label"
                                for="av-enabled"
                            >Activer les animateurs virtuels sur cette station</label>
                        </div>
                        <div class="text-body-secondary">
                            <template v-if="status.on_air">
                                {{ status.ready ? 'À l\'antenne maintenant' : 'Programmé maintenant (plugin non activé)' }} :
                                <strong>{{ status.on_air.host }}</strong>
                                ({{ status.on_air.start }}–{{ status.on_air.end }})
                            </template>
                            <template v-else>
                                Aucun animateur programmé en ce moment.
                            </template>
                            <br>
                            Interventions générées aujourd'hui : <strong>{{ status.today }}</strong>
                            <template v-if="settings.limits.daily_enabled">
                                / {{ settings.limits.daily_max }}
                            </template>
                            <template v-if="status.today_reused">
                                · réutilisées : <strong>{{ status.today_reused }}</strong>
                            </template>
                            <template
                                v-for="c in status.credits || []"
                                :key="c.id"
                            >
                                <br>
                                ElevenLabs {{ c.name }} :
                                <span
                                    v-if="c.error"
                                    class="text-warning-emphasis"
                                    :title="c.error"
                                >crédits illisibles</span>
                                <strong
                                    v-else-if="c.remaining !== null"
                                    :class="c.exhausted ? 'text-danger' : (c.low ? 'text-warning-emphasis' : '')"
                                >{{ formatNumber(c.remaining) }} crédits restants</strong>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="card-body pt-0">
                    <ul
                        class="nav nav-tabs"
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
                                @click="openTab(t.key)"
                            >
                                {{ t.label }}
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Animateurs -->
                <div
                    v-show="tab === 'hosts'"
                    class="card-body pt-0"
                >
                    <div class="buttons mb-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            @click="editHost(-1)"
                        >
                            <svg
                                class="icon"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            ><path
                                fill="currentColor"
                                d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"
                            /></svg>
                            Ajouter un animateur
                        </button>
                    </div>

                    <div
                        v-for="warning in overlaps"
                        :key="warning"
                        class="alert alert-warning py-2"
                    >
                        Les tranches de {{ warning }} se chevauchent : c'est l'animateur placé le plus haut dans la
                        liste qui prend l'antenne.
                    </div>

                    <p
                        v-if="settings.hosts.length === 0"
                        class="mb-0"
                    >
                        Aucun animateur pour l'instant. Commencez par renseigner les clés API dans l'onglet
                        « Services IA », puis ajoutez un animateur.
                    </p>
                    <div
                        v-else
                        class="table-responsive"
                    >
                        <table class="table table-striped table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Animateur</th>
                                    <th>Tranches horaires</th>
                                    <th>Prompts</th>
                                    <th class="text-end">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(host, index) in settings.hosts"
                                    :key="host.id || index"
                                >
                                    <td>
                                        <h5 class="mb-1">
                                            {{ host.name }}
                                            <span
                                                v-if="!host.enabled"
                                                class="badge text-bg-secondary ms-1"
                                            >Désactivé</span>
                                        </h5>
                                        <small class="text-body-secondary">Voix : <code>{{ host.voice_id || '—' }}</code></small>
                                    </td>
                                    <td>
                                        <div
                                            v-for="slot in host.slots"
                                            :key="slot.id"
                                            class="small"
                                        >
                                            {{ describeSlot(slot) }}
                                        </div>
                                        <span
                                            v-if="host.slots.length === 0"
                                            class="text-body-secondary small"
                                        >Aucune (tests uniquement)</span>
                                    </td>
                                    <td class="small">
                                        {{ host.prompts.filter((p) => p.enabled).length }} actif(s)
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="btn-group btn-group-sm">
                                            <button
                                                type="button"
                                                class="btn btn-primary"
                                                @click="editHost(index)"
                                            >
                                                Modifier
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                :disabled="testing !== null || !host.id"
                                                :title="host.id ? '' : 'Enregistrez d\'abord'"
                                                @click="runTest(host)"
                                            >
                                                <span
                                                    v-if="testing === host.id"
                                                    class="spinner-border spinner-border-sm me-1"
                                                />
                                                Tester
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                :disabled="index === 0"
                                                title="Monter (priorité)"
                                                @click="moveHost(index, -1)"
                                            >
                                                ↑
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                :disabled="index === settings.hosts.length - 1"
                                                title="Descendre"
                                                @click="moveHost(index, 1)"
                                            >
                                                ↓
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-danger"
                                                @click="removeHost(index)"
                                            >
                                                Supprimer
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Services IA -->
                <div
                    v-show="tab === 'services'"
                    class="card-body pt-0"
                >
                    <div class="row g-4">
                        <fieldset class="col-lg-6">
                            <legend class="fs-5">
                                DeepSeek (écriture des textes)
                            </legend>
                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="av-ds-key"
                                >Clé API DeepSeek</label>
                                <div class="input-group">
                                    <input
                                        id="av-ds-key"
                                        v-model="settings.deepseek.api_key"
                                        type="password"
                                        class="form-control"
                                        autocomplete="new-password"
                                        placeholder="sk-…"
                                        @focus="clearMask('deepseek')"
                                    >
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        :disabled="checking === 'deepseek'"
                                        @click="checkDeepSeek"
                                    >
                                        Vérifier
                                    </button>
                                </div>
                                <div class="form-text">
                                    <template v-if="settings.deepseek.api_key_hint">
                                        Clé enregistrée ({{ settings.deepseek.api_key_hint }}). Laissez le champ tel quel
                                        pour la garder.
                                    </template>
                                    <template v-else>
                                        À créer sur platform.deepseek.com, rubrique « API keys ».
                                    </template>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-ds-model"
                                    >Modèle</label>
                                    <input
                                        id="av-ds-model"
                                        v-model.trim="settings.deepseek.model"
                                        class="form-control"
                                        list="av-ds-models"
                                    >
                                    <datalist id="av-ds-models">
                                        <option
                                            v-for="m in deepSeekModels"
                                            :key="m"
                                            :value="m"
                                        />
                                    </datalist>
                                </div>
                                <div class="col-sm-3">
                                    <label
                                        class="form-label"
                                        for="av-ds-temp"
                                    >Créativité</label>
                                    <input
                                        id="av-ds-temp"
                                        v-model.number="settings.deepseek.temperature"
                                        type="number"
                                        min="0"
                                        max="2"
                                        step="0.1"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-3">
                                    <label
                                        class="form-label"
                                        for="av-ds-tokens"
                                    >Jetons max.</label>
                                    <input
                                        id="av-ds-tokens"
                                        v-model.number="settings.deepseek.max_tokens"
                                        type="number"
                                        min="50"
                                        max="4000"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            id="av-ds-thinking"
                                            v-model="settings.deepseek.thinking"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <label
                                            class="form-check-label"
                                            for="av-ds-thinking"
                                        >Mode « réflexion » (plus lent et plus cher, rarement utile ici)</label>
                                    </div>
                                    <div class="form-text">
                                        Créativité : 0 = textes prévisibles, 1 = naturel, 1,5 et plus = très varié.
                                    </div>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="col-12">
                            <legend class="fs-5">
                                ElevenLabs (voix)
                            </legend>
                            <p class="form-text mt-0">
                                Jusqu'à {{ maxAccounts }} comptes ElevenLabs pour cette station. Chaque animateur utilise
                                les voix et les crédits d'un compte (onglet « Animateur » de sa fiche). Chaque clé doit avoir
                                les permissions « Text to Speech », « Voices : lecture » et, pour afficher les crédits,
                                « User : lecture ».
                            </p>
                            <div
                                v-for="(account, index) in settings.elevenlabs.accounts"
                                :key="account.id || `new-${index}`"
                                class="border rounded p-3 mb-3"
                            >
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-3">
                                        <label
                                            class="form-label"
                                            :for="`av-acc-name-${index}`"
                                        >Nom du compte</label>
                                        <input
                                            :id="`av-acc-name-${index}`"
                                            v-model="account.name"
                                            class="form-control"
                                            maxlength="60"
                                        >
                                    </div>
                                    <div class="col-md-6">
                                        <label
                                            class="form-label"
                                            :for="`av-acc-key-${index}`"
                                        >Clé API</label>
                                        <div class="input-group">
                                            <input
                                                :id="`av-acc-key-${index}`"
                                                v-model="account.api_key"
                                                type="password"
                                                class="form-control"
                                                autocomplete="new-password"
                                                placeholder="sk_…"
                                                @focus="account.api_key === '••••••••' && (account.api_key = '')"
                                            >
                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                :disabled="checking === `account-${index}`"
                                                @click="checkAccount(account, index)"
                                            >
                                                Vérifier
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-3 text-md-end">
                                        <button
                                            type="button"
                                            class="btn btn-outline-danger"
                                            :disabled="accountUsers(account).length > 0"
                                            :title="accountUsers(account).length ? 'Utilisé par ' + accountUsers(account).join(', ') : ''"
                                            @click="settings.elevenlabs.accounts.splice(index, 1)"
                                        >
                                            Retirer
                                        </button>
                                    </div>
                                </div>
                                <div class="form-text">
                                    <template v-if="account.api_key_hint">
                                        Clé enregistrée ({{ account.api_key_hint }}).
                                    </template>
                                    <template v-if="accountUsers(account).length">
                                        Utilisé par : {{ accountUsers(account).join(', ') }}.
                                    </template>
                                    <template v-else-if="account.id">
                                        Aucun animateur n'utilise ce compte.
                                    </template>
                                </div>
                                <CreditsBar
                                    v-if="creditsFor(account)"
                                    class="mt-2"
                                    :credits="creditsFor(account)"
                                />
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :disabled="settings.elevenlabs.accounts.length >= maxAccounts"
                                    @click="addAccount"
                                >
                                    Ajouter un compte ElevenLabs
                                </button>
                                <button
                                    v-if="settings.elevenlabs.accounts.length"
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    :disabled="creditsLoading"
                                    @click="refreshCredits"
                                >
                                    Actualiser les crédits
                                </button>
                                <small class="text-body-secondary">
                                    Les crédits sont relus toutes les 10 minutes et après chaque intervention générée.
                                </small>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-el-model"
                                    >Modèle de voix</label>
                                    <select
                                        id="av-el-model"
                                        v-model="settings.elevenlabs.model_id"
                                        class="form-select"
                                    >
                                        <option
                                            v-for="m in elevenLabsModels"
                                            :key="m.value"
                                            :value="m.value"
                                        >
                                            {{ m.label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label
                                        class="form-label"
                                        for="av-el-low"
                                    >Alerte sous (crédits)</label>
                                    <input
                                        id="av-el-low"
                                        v-model.number="settings.elevenlabs.low_credits"
                                        type="number"
                                        min="0"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-2">
                                    <label
                                        class="form-label"
                                        for="av-el-lang"
                                    >Code langue</label>
                                    <input
                                        id="av-el-lang"
                                        v-model.trim="settings.elevenlabs.language_code"
                                        class="form-control"
                                        maxlength="3"
                                        placeholder="fr"
                                    >
                                </div>
                            </div>
                            <div class="form-text">
                                Le code langue force la prononciation (modèles v4, v3, Flash et Turbo ; ignoré par
                                Multilingual v2, qui détecte la langue seul). Changer de modèle change la voix : les clips
                                de la bibliothèque enregistrés avec l'ancien modèle ne sont plus réutilisés.
                            </div>
                        </fieldset>

                        <fieldset class="col-lg-6">
                            <legend class="fs-5">
                                Limites et conservation
                            </legend>
                            <div class="form-check form-switch mb-2">
                                <input
                                    id="av-limit"
                                    v-model="settings.limits.daily_enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label
                                    class="form-check-label"
                                    for="av-limit"
                                >Limiter le nombre d'interventions par jour</label>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-limit-max"
                                    >Maximum par jour</label>
                                    <input
                                        id="av-limit-max"
                                        v-model.number="settings.limits.daily_max"
                                        type="number"
                                        min="1"
                                        max="5000"
                                        class="form-control"
                                        :disabled="!settings.limits.daily_enabled"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-retention"
                                    >Garder les fichiers (jours)</label>
                                    <input
                                        id="av-retention"
                                        v-model.number="settings.retention_days"
                                        type="number"
                                        min="1"
                                        max="365"
                                        class="form-control"
                                    >
                                </div>
                            </div>
                            <div class="form-text">
                                La limite compte toutes les interventions générées, tests compris, depuis minuit.
                                Une fois atteinte, la musique continue sans animateur jusqu'au lendemain.
                            </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Fond musical & son -->
                <div
                    v-show="tab === 'sound'"
                    class="card-body pt-0"
                >
                    <div class="row g-4">
                        <fieldset class="col-lg-6">
                            <legend class="fs-5">
                                Fond musical
                            </legend>
                            <div class="form-check form-switch mb-3">
                                <input
                                    id="av-bed"
                                    v-model="settings.bed.enabled"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label
                                    class="form-check-label"
                                    for="av-bed"
                                >Mixer la voix sur un fond musical</label>
                            </div>
                            <div class="mb-3">
                                <label
                                    class="form-label"
                                    for="av-bed-pl"
                                >Playlist des fonds musicaux</label>
                                <select
                                    id="av-bed-pl"
                                    v-model="settings.bed.playlist_id"
                                    class="form-select"
                                    :disabled="!settings.bed.enabled"
                                >
                                    <option :value="null">
                                        — Aucune (voix seule) —
                                    </option>
                                    <option
                                        v-for="p in playlists"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ playlistLabel(p) }}
                                    </option>
                                </select>
                                <div class="form-text">
                                    Un morceau de cette playlist est tiré au hasard pour chaque intervention. Choisissez
                                    une playlist de type « Avancée » pour qu'elle ne passe pas seule à l'antenne. Chaque
                                    animateur peut avoir sa propre playlist.
                                </div>
                                <div
                                    v-if="selectedBedPlaylist && !selectedBedPlaylist.advanced"
                                    class="alert alert-warning py-2 mt-2 mb-0"
                                >
                                    Cette playlist n'est pas de type « Avancée » : ses morceaux risquent aussi
                                    d'être diffusés par l'AutoDJ.
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-duck"
                                    >Baisse de la musique sous la voix (dB)</label>
                                    <input
                                        id="av-duck"
                                        v-model.number="settings.bed.duck_db"
                                        type="number"
                                        min="0"
                                        max="60"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-gain"
                                    >Volume général du fond (dB)</label>
                                    <input
                                        id="av-gain"
                                        v-model.number="settings.bed.bed_gain_db"
                                        type="number"
                                        min="-40"
                                        max="12"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-intro"
                                    >Musique seule avant la voix (s)</label>
                                    <input
                                        id="av-intro"
                                        v-model.number="settings.bed.intro"
                                        type="number"
                                        min="0"
                                        max="30"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-outro"
                                    >Musique seule après la voix (s)</label>
                                    <input
                                        id="av-outro"
                                        v-model.number="settings.bed.outro"
                                        type="number"
                                        min="0"
                                        max="30"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-4">
                                    <label
                                        class="form-label"
                                        for="av-fin"
                                    >Fondu d'entrée (s)</label>
                                    <input
                                        id="av-fin"
                                        v-model.number="settings.bed.fade_in"
                                        type="number"
                                        min="0"
                                        max="10"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-4">
                                    <label
                                        class="form-label"
                                        for="av-fout"
                                    >Fondu de sortie (s)</label>
                                    <input
                                        id="av-fout"
                                        v-model.number="settings.bed.fade_out"
                                        type="number"
                                        min="0"
                                        max="15"
                                        step="0.5"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-4">
                                    <label
                                        class="form-label"
                                        for="av-ramp"
                                    >Transition du volume (s)</label>
                                    <input
                                        id="av-ramp"
                                        v-model.number="settings.bed.ramp"
                                        type="number"
                                        min="0.05"
                                        max="5"
                                        step="0.1"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            id="av-random"
                                            v-model="settings.bed.random_start"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <label
                                            class="form-check-label"
                                            for="av-random"
                                        >Démarrer le fond à un endroit au hasard du morceau (sinon au début)</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text mt-2">
                                La longueur du fond s'adapte à la voix : musique seule, puis musique baissée de
                                {{ settings.bed.duck_db }} dB pendant la voix, puis remontée et fondu de sortie. Un fond
                                plus court que nécessaire est bouclé.
                            </div>
                        </fieldset>

                        <fieldset class="col-lg-6">
                            <legend class="fs-5">
                                Fichier final et affichage
                            </legend>
                            <div class="form-check form-switch mb-2">
                                <input
                                    id="av-norm"
                                    v-model="settings.output.normalize"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label
                                    class="form-check-label"
                                    for="av-norm"
                                >Normaliser le volume de l'intervention</label>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-lufs"
                                    >Volume cible (LUFS)</label>
                                    <input
                                        id="av-lufs"
                                        v-model.number="settings.output.lufs"
                                        type="number"
                                        min="-30"
                                        max="-6"
                                        step="0.5"
                                        class="form-control"
                                        :disabled="!settings.output.normalize"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-bitrate"
                                    >Qualité mp3</label>
                                    <select
                                        id="av-bitrate"
                                        v-model.number="settings.output.bitrate"
                                        class="form-select"
                                    >
                                        <option
                                            v-for="b in [128, 160, 192, 256, 320]"
                                            :key="b"
                                            :value="b"
                                        >
                                            {{ b }} kbit/s
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text mb-3">
                                -14 LUFS correspond au niveau habituel de la musique diffusée en ligne. Réglez ce
                                niveau sur celui de vos morceaux pour que l'animateur ne soit ni trop fort ni trop faible.
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-mtitle"
                                    >Titre affiché</label>
                                    <input
                                        id="av-mtitle"
                                        v-model="settings.output.meta_title"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-sm-6">
                                    <label
                                        class="form-label"
                                        for="av-martist"
                                    >Artiste affiché</label>
                                    <input
                                        id="av-martist"
                                        v-model="settings.output.meta_artist"
                                        class="form-control"
                                    >
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input
                                            id="av-hide"
                                            v-model="settings.output.hide_metadata"
                                            class="form-check-input"
                                            type="checkbox"
                                        >
                                        <label
                                            class="form-check-label"
                                            for="av-hide"
                                        >Ne pas changer le titre affiché pendant l'intervention (comme un jingle)</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">
                                Variables possibles : {animateur}, {station}, {heure}…
                            </div>
                        </fieldset>
                    </div>
                </div>

                <!-- Consignes générales -->
                <div
                    v-show="tab === 'prompt'"
                    class="card-body pt-0"
                >
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label
                                class="form-label"
                                for="av-lang"
                            >Langue de l'antenne</label>
                            <input
                                id="av-lang"
                                v-model="settings.language"
                                class="form-control"
                            >
                        </div>
                        <div class="col-md-6">
                            <label
                                class="form-label"
                                for="av-repeat"
                            >Éviter les répétitions</label>
                            <select
                                id="av-repeat"
                                v-model.number="settings.avoid_repeat"
                                class="form-select"
                            >
                                <option :value="0">
                                    Non
                                </option>
                                <option
                                    v-for="n in [1, 2, 3, 5, 8, 10]"
                                    :key="n"
                                    :value="n"
                                >
                                    Montrer à DeepSeek les {{ n }} dernières interventions de l'animateur
                                </option>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input
                                    id="av-radio-style"
                                    v-model="settings.radio_style"
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                >
                                <label
                                    class="form-check-label"
                                    for="av-radio-style"
                                >Écriture radio : ajouter automatiquement les règles d'écriture pour l'oreille</label>
                            </div>
                            <div class="form-text">
                                Style oral, idées reliées pour garder l'élan de la voix, exclamations et questions.
                                Selon le modèle de voix, DeepSeek ajoute aussi une ou deux indications de jeu comme
                                [warmly] (Eleven v3 et v4) ou une ou deux pauses &lt;break&gt; (autres modèles).
                                Le bouton « Aperçu » d'un prompt montre le texte complet envoyé à DeepSeek.
                            </div>
                        </div>
                        <div class="col-12">
                            <label
                                class="form-label"
                                for="av-system"
                            >Consignes communes à tous les animateurs (prompt système)</label>
                            <textarea
                                id="av-system"
                                v-model="settings.system_prompt"
                                class="form-control font-monospace"
                                rows="12"
                            />
                            <div class="d-flex justify-content-between flex-wrap gap-2 mt-1">
                                <div class="form-text">
                                    La personnalité de chaque animateur est ajoutée après ces consignes, puis le prompt
                                    choisi pour l'intervention.
                                </div>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-secondary"
                                    @click="settings.system_prompt = defaultSystemPrompt"
                                >
                                    Rétablir le texte d'origine
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <h3 class="fs-6 mt-2">
                                Variables utilisables dans tous les textes
                            </h3>
                            <table class="table table-sm small mb-0">
                                <tbody>
                                    <tr
                                        v-for="(label, name) in variables"
                                        :key="name"
                                    >
                                        <td class="text-nowrap">
                                            <code>{{ '{' + name + '}' }}</code>
                                        </td>
                                        <td>{{ label }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Bibliothèque -->
                <div
                    v-show="tab === 'library'"
                    class="card-body pt-0"
                >
                    <p class="text-body-secondary">
                        Voix enregistrées à partir des prompts « intemporels », réutilisables sur un nouveau fond musical
                        sans appel aux API. Écoutez-les et supprimez celles qui ne conviennent pas.
                    </p>
                    <div class="buttons mb-3">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="libraryLoading"
                            @click="loadLibrary()"
                        >
                            Actualiser
                        </button>
                    </div>
                    <p
                        v-if="library.length === 0"
                        class="mb-0"
                    >
                        {{ libraryLoading ? 'Chargement…' : 'Aucun animateur.' }}
                    </p>
                    <div
                        v-for="lib in library"
                        :key="lib.host_id"
                        class="card mb-3"
                    >
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <h3 class="fs-6 mb-0 me-auto">
                                {{ lib.name }}
                                <span class="badge text-bg-secondary ms-1">{{ lib.valid }} clip(s)</span>
                            </h3>
                            <small class="text-body-secondary">
                                <template v-if="!lib.config.enabled">
                                    Réutilisation désactivée (la bibliothèque se remplit quand même)
                                </template>
                                <template v-else-if="lib.valid < lib.config.min_size">
                                    Réutilisation à partir de {{ lib.config.min_size }} clips
                                </template>
                                <template v-else>
                                    Réutilisation actuelle : {{ Math.round(lib.share * 100) }} % des interventions entre
                                    deux morceaux
                                </template>
                            </small>
                            <button
                                v-if="lib.entries.some((e) => e.obsolete)"
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                @click="deleteObsolete(lib)"
                            >
                                Supprimer les clips de l'ancienne voix
                            </button>
                        </div>
                        <div class="card-body">
                            <div
                                v-if="lib.timeless_prompts === 0"
                                class="alert alert-warning py-2"
                            >
                                Aucun prompt actif de cet animateur n'est marqué « intemporel » : la bibliothèque ne se
                                remplit pas.
                            </div>
                            <p
                                v-if="lib.entries.length === 0"
                                class="mb-0 text-body-secondary"
                            >
                                Bibliothèque vide pour l'instant.
                            </p>
                            <div
                                v-else
                                class="table-responsive"
                            >
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Texte</th>
                                            <th>Diffusions</th>
                                            <th>Écoute</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="entry in lib.entries"
                                            :key="entry.id"
                                        >
                                            <td class="small">
                                                <div class="av-text">
                                                    {{ entry.text }}
                                                </div>
                                                <div class="text-body-secondary">
                                                    Créé le {{ formatTimestamp(entry.created_at) }}
                                                    <template v-if="entry.prompt_name">
                                                        · {{ entry.prompt_name }}
                                                    </template>
                                                    <span
                                                        v-if="entry.obsolete"
                                                        class="badge text-bg-warning ms-1"
                                                        title="Enregistré avec une autre voix ou d'autres réglages : n'est plus réutilisé"
                                                    >Ancienne voix</span>
                                                </div>
                                            </td>
                                            <td class="small text-nowrap">
                                                {{ entry.uses }}
                                                <div
                                                    v-if="entry.last_used_at"
                                                    class="text-body-secondary"
                                                >
                                                    dernière : {{ formatTimestamp(entry.last_used_at) }}
                                                </div>
                                            </td>
                                            <td>
                                                <audio
                                                    controls
                                                    preload="none"
                                                    :src="api.libraryAudioUrl(lib.host_id, entry.id)"
                                                />
                                            </td>
                                            <td class="text-end">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-danger"
                                                    @click="deleteEntry(lib, entry)"
                                                >
                                                    Supprimer
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Historique -->
                <div
                    v-show="tab === 'history'"
                    class="card-body pt-0"
                >
                    <div class="buttons mb-3">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="historyLoading"
                            @click="loadHistory()"
                        >
                            Actualiser
                        </button>
                        <button
                            type="button"
                            class="btn btn-danger"
                            :disabled="history.length === 0"
                            @click="clearHistory"
                        >
                            Effacer l'historique
                        </button>
                    </div>
                    <p
                        v-if="history.length === 0"
                        class="mb-0"
                    >
                        {{ historyLoading ? 'Chargement…' : 'Aucune intervention pour l\'instant.' }}
                    </p>
                    <div
                        v-else
                        class="table-responsive"
                    >
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Animateur</th>
                                    <th>Statut</th>
                                    <th>Texte</th>
                                    <th>Écoute</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="job in history"
                                    :key="job.id"
                                >
                                    <td class="small text-nowrap">
                                        {{ formatDateTime(job.created_at) }}
                                        <div
                                            v-if="job.play_at"
                                            class="text-body-secondary"
                                        >
                                            antenne ≈ {{ formatDateTime(job.play_at) }}
                                        </div>
                                    </td>
                                    <td class="small">
                                        {{ job.host_name }}
                                        <div class="text-body-secondary">
                                            {{ kinds[job.kind] || job.kind }}<template v-if="job.prompt_name">
                                                · {{ job.prompt_name }}
                                            </template>
                                            <template v-if="job.account_name && !job.reused">
                                                · {{ job.account_name }}
                                            </template>
                                        </div>
                                    </td>
                                    <td>
                                        <span
                                            class="badge"
                                            :class="(statuses[job.status] || {}).cls"
                                        >{{ (statuses[job.status] || {}).label || job.status }}</span>
                                        <span
                                            v-if="job.reused"
                                            class="badge text-bg-primary ms-1"
                                            title="Voix reprise de la bibliothèque : aucun appel aux API"
                                        >Réutilisée</span>
                                        <span
                                            v-else-if="job.library_id"
                                            class="badge text-bg-light border ms-1"
                                            title="Voix ajoutée à la bibliothèque"
                                        >+ bibliothèque</span>
                                        <div
                                            v-if="job.duration"
                                            class="small text-body-secondary"
                                        >
                                            {{ formatDuration(job.duration) }}
                                        </div>
                                    </td>
                                    <td class="small">
                                        <div
                                            v-if="job.text"
                                            class="av-text"
                                            :class="{ 'av-clamp': !expanded[job.id] }"
                                            :title="expanded[job.id] ? '' : 'Cliquer pour tout lire'"
                                            @click="expanded[job.id] = !expanded[job.id]"
                                        >
                                            {{ job.text }}
                                        </div>
                                        <div
                                            v-if="job.error"
                                            class="text-danger"
                                        >
                                            {{ job.error }}
                                        </div>
                                        <div
                                            v-if="job.library_note"
                                            class="text-body-secondary fst-italic"
                                        >
                                            {{ job.library_note }}
                                        </div>
                                        <div
                                            v-if="job.bed && job.bed.title"
                                            class="text-body-secondary"
                                        >
                                            Fond : {{ job.bed.artist ? job.bed.artist + ' – ' : '' }}{{ job.bed.title }}
                                        </div>
                                    </td>
                                    <td class="text-nowrap">
                                        <template v-if="job.has_clip">
                                            <audio
                                                controls
                                                preload="none"
                                                :src="api.clipUrl(job.id)"
                                            />
                                            <div>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-link px-0"
                                                    @click="broadcast(job)"
                                                >
                                                    Diffuser maintenant
                                                </button>
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-show="tab !== 'history' && tab !== 'library'"
                    class="card-footer av-save-bar d-flex align-items-center gap-3"
                >
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="saving"
                        @click="save()"
                    >
                        <span
                            v-if="saving"
                            class="spinner-border spinner-border-sm me-1"
                        />
                        Enregistrer
                    </button>
                    <span
                        v-if="dirty"
                        class="text-warning-emphasis small"
                    >Modifications non enregistrées</span>
                </div>
            </template>
        </section>

        <HostModal
            v-if="editing !== null"
            :api="api"
            :host="editing.host"
            :is-new="editing.index < 0"
            :playlists="playlists"
            :variables="variables"
            :settings="settings"
            :apply="applyHost"
            @close="editing = null"
        />

        <div
            v-if="testResult"
            class="animateurs-virtuels-modal"
        >
            <div
                class="modal fade show d-block"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="av-test-title"
            >
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h1
                                id="av-test-title"
                                class="modal-title fs-5"
                            >
                                Test : {{ testResult.host_name }}
                            </h1>
                            <button
                                type="button"
                                class="btn-close"
                                aria-label="Fermer"
                                @click="testResult = null"
                            />
                        </div>
                        <div class="modal-body">
                            <div
                                v-if="testResult.status !== 'done'"
                                class="alert alert-danger"
                            >
                                {{ testResult.error || 'Échec de la génération.' }}
                            </div>
                            <p
                                v-if="testResult.text"
                                class="av-text fs-6"
                            >
                                {{ testResult.text }}
                            </p>
                            <audio
                                v-if="testResult.has_clip"
                                controls
                                autoplay
                                class="w-100"
                                style="max-width: none"
                                :src="api.clipUrl(testResult.id)"
                            />
                            <p class="small text-body-secondary mt-2 mb-0">
                                Prompt : {{ testResult.prompt_name }}
                                <template v-if="testResult.duration">
                                    · durée {{ formatDuration(testResult.duration) }}
                                    (voix {{ formatDuration(testResult.voice_duration) }})
                                </template>
                                <template v-if="testResult.characters">
                                    · {{ testResult.characters }} caractères ElevenLabs
                                </template>
                                <template v-if="testResult.bed && testResult.bed.title">
                                    · fond : {{ testResult.bed.title }}
                                </template>
                            </p>
                            <p
                                v-if="testResult.library_note"
                                class="small text-body-secondary fst-italic mt-1 mb-0"
                            >
                                {{ testResult.library_note }}
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button
                                v-if="testResult.has_clip"
                                type="button"
                                class="btn btn-primary"
                                @click="broadcast(testResult)"
                            >
                                Diffuser maintenant
                            </button>
                            <button
                                type="button"
                                class="btn btn-secondary"
                                @click="testResult = null"
                            >
                                Fermer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show" />
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import { createApi } from "./api.js";
import HostModal from "./HostModal.vue";
import CreditsBar from "./CreditsBar.vue";
import {
    DEEPSEEK_MODELS, ELEVENLABS_MODELS, KINDS, STATUSES,
    clone, describeSlot, findOverlaps, formatDateTime, formatDuration, formatNumber, formatTimestamp,
} from "./helpers.js";

const props = defineProps({
    base: { type: String, required: true },
});

const api = createApi(props.base);
const tabs = [
    { key: "hosts", label: "Animateurs" },
    { key: "services", label: "Services IA" },
    { key: "sound", label: "Fond musical et son" },
    { key: "prompt", label: "Consignes générales" },
    { key: "library", label: "Bibliothèque" },
    { key: "history", label: "Historique" },
];
const kinds = KINDS;
const statuses = STATUSES;
const elevenLabsModels = ELEVENLABS_MODELS;

const loading = ref(false);
const loaded = ref(false);
const loadError = ref("");
const version = ref("");
const station = ref(null);
const settings = ref(null);
const savedSnapshot = ref("");
const playlists = ref([]);
const variables = ref({});
const defaultSystemPrompt = ref("");
const defaultHost = ref(null);
const status = ref({});
const notice = ref(null);
const tab = ref("hosts");
const saving = ref(false);
const checking = ref(null);
const testing = ref(null);
const testResult = ref(null);
const editing = ref(null);
const history = ref([]);
const historyLoading = ref(false);
const creditsLoading = ref(false);
const maxAccounts = 5;
const library = ref([]);
const libraryLoading = ref(false);
const expanded = reactive({});
const deepSeekModels = ref([...DEEPSEEK_MODELS]);
let timer = null;

const dirty = computed(() => settings.value !== null && JSON.stringify(settings.value) !== savedSnapshot.value);
const overlaps = computed(() => (settings.value ? findOverlaps(settings.value.hosts) : []));
const selectedBedPlaylist = computed(() => playlists.value.find((p) => p.id === settings.value?.bed.playlist_id));

const problems = computed(() => {
    const list = [];
    if (!status.value.data_writable) {
        list.push(`Le dossier de données du plugin (${status.value.data_dir}) n'est pas accessible en écriture : `
            + "relancez « sudo bash bin/setup.sh » dans le dossier du plugin.");
    }
    if (!status.value.ffmpeg) list.push("ffmpeg est introuvable : le mixage du fond musical est impossible.");
    if (!status.value.autodj) {
        list.push("L'AutoDJ de cette station est désactivé : les animateurs ne peuvent intervenir que si l'AutoDJ "
            + "construit la file d'attente.");
    }
    if (settings.value?.enabled && !settings.value.hosts.some((h) => h.enabled && h.slots.length)) {
        list.push("Activé, mais aucun animateur actif n'a de tranche horaire.");
    }
    return list;
});

function applyState(data) {
    settings.value = data.settings;
    savedSnapshot.value = JSON.stringify(data.settings);
    if (data.status) status.value = data.status;
}

async function reload() {
    loading.value = true;
    try {
        const data = await api.state();
        version.value = data.version;
        station.value = data.station;
        playlists.value = data.playlists;
        variables.value = data.variables;
        defaultSystemPrompt.value = data.default_system_prompt;
        defaultHost.value = data.default_host;
        applyState(data);
        loadError.value = "";
        loaded.value = true;
    } catch (error) {
        loadError.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function save(quiet = false) {
    saving.value = true;
    try {
        const result = await api.save(settings.value);
        applyState(result);
        if (!quiet) notice.value = { type: "success", text: result.message };
        return true;
    } catch (error) {
        notice.value = { type: "error", text: error.message };
        if (quiet) throw error;
        return false;
    } finally {
        saving.value = false;
    }
}

function clearMask(service) {
    if (settings.value[service].api_key === "••••••••") settings.value[service].api_key = "";
}

function openTab(key) {
    tab.value = key;
    if (key === "history") loadHistory();
    if (key === "library") loadLibrary();
}

function playlistLabel(p) {
    let label = p.name;
    if (p.advanced) label += " (Avancée)";
    if (!p.enabled) label += " – désactivée";
    return label;
}

function editHost(index) {
    const host = index < 0
        ? { ...clone(defaultHost.value), name: "" }
        : clone(settings.value.hosts[index]);
    editing.value = { index, host };
}

/** Called by the host window: puts the host in the list and saves everything. */
async function applyHost(host) {
    const previous = clone(settings.value.hosts);
    if (editing.value.index < 0) settings.value.hosts.push(host);
    else settings.value.hosts[editing.value.index] = host;
    try {
        await save(true);
        notice.value = { type: "success", text: `« ${host.name} » est enregistré.` };
        editing.value = null;
    } catch (error) {
        settings.value.hosts = previous;
        throw error;
    }
}

async function moveHost(index, delta) {
    const hosts = settings.value.hosts;
    [hosts[index], hosts[index + delta]] = [hosts[index + delta], hosts[index]];
    await save();
}

async function removeHost(index) {
    const host = settings.value.hosts[index];
    if (!window.confirm(`Supprimer l'animateur « ${host.name} » ?`)) return;
    settings.value.hosts.splice(index, 1);
    await save();
}

async function runTest(host) {
    if (dirty.value && !(await save(false))) return;
    testing.value = host.id;
    notice.value = null;
    try {
        const result = await api.test(host.id);
        testResult.value = result.job;
    } catch (error) {
        if (error.data?.job) testResult.value = error.data.job;
        else notice.value = { type: "error", text: error.message };
    } finally {
        testing.value = null;
        refreshStatus();
    }
}

async function broadcast(job) {
    if (!window.confirm("Diffuser cette intervention à la fin du morceau en cours ?")) return;
    try {
        const result = await api.broadcast(job.id);
        notice.value = { type: "success", text: result.message };
        testResult.value = null;
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    }
}

async function checkDeepSeek() {
    checking.value = "deepseek";
    try {
        const result = await api.checkDeepSeek(settings.value.deepseek.api_key);
        if (result.models?.length) {
            deepSeekModels.value = [...new Set([...result.models, ...DEEPSEEK_MODELS])];
        }
        notice.value = { type: "success", text: result.message };
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    } finally {
        checking.value = null;
    }
}

function accountUsers(account) {
    const first = settings.value.elevenlabs.accounts[0];
    return settings.value.hosts
        .filter((h) => h.account_id === account.id || (!h.account_id && first === account))
        .map((h) => h.name);
}

function creditsFor(account) {
    return (status.value.credits || []).find((c) => c.id === account.id) || null;
}

function addAccount() {
    settings.value.elevenlabs.accounts.push({
        id: "", name: `Compte ${settings.value.elevenlabs.accounts.length + 1}`, api_key: "",
    });
}

async function checkAccount(account, index) {
    checking.value = `account-${index}`;
    try {
        const result = await api.checkElevenLabs(account.api_key, "", account.id);
        notice.value = { type: "success", text: `${account.name} : ${result.message}` };
    } catch (error) {
        notice.value = { type: "error", text: `${account.name} : ${error.message}` };
    } finally {
        checking.value = null;
    }
}

async function refreshCredits() {
    creditsLoading.value = true;
    try {
        const data = await api.credits(true);
        status.value = { ...status.value, credits: data.credits };
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    } finally {
        creditsLoading.value = false;
    }
}

async function loadHistory(silent = false) {
    if (!silent) historyLoading.value = true;
    try {
        const data = await api.history();
        history.value = data.rows;
        status.value = data.status;
    } catch (error) {
        if (!silent) notice.value = { type: "error", text: error.message };
    } finally {
        historyLoading.value = false;
    }
}

async function refreshStatus() {
    try {
        const data = await api.history();
        status.value = data.status;
        if (tab.value === "history") history.value = data.rows;
    } catch {
        // ignored: refreshed again later
    }
}

async function clearHistory() {
    if (!window.confirm("Effacer l'historique et les fichiers des interventions déjà diffusées ?")) return;
    try {
        const result = await api.clearHistory();
        notice.value = { type: "success", text: result.message };
        await loadHistory();
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    }
}

async function loadLibrary() {
    libraryLoading.value = true;
    try {
        library.value = (await api.library()).hosts;
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    } finally {
        libraryLoading.value = false;
    }
}

async function deleteEntry(lib, entry) {
    if (!window.confirm(`Supprimer ce clip de la bibliothèque de « ${lib.name} » ?\n\n${entry.text}`)) return;
    try {
        const result = await api.libraryDelete(lib.host_id, entry.id);
        notice.value = { type: "success", text: result.message };
        lib.entries = lib.entries.filter((e) => e.id !== entry.id);
        if (!entry.obsolete) lib.valid -= 1;
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    }
}

async function deleteObsolete(lib) {
    if (!window.confirm(`Supprimer les clips de « ${lib.name} » enregistrés avec une ancienne voix ?`)) return;
    try {
        const result = await api.libraryDeleteObsolete(lib.host_id);
        notice.value = { type: "success", text: result.message };
        await loadLibrary();
    } catch (error) {
        notice.value = { type: "error", text: error.message };
    }
}

function beforeUnload(event) {
    if (dirty.value) {
        event.preventDefault();
        event.returnValue = "";
    }
}

onMounted(() => {
    reload();
    timer = window.setInterval(() => {
        if (loaded.value && document.visibilityState === "visible") refreshStatus();
    }, 20000);
    window.addEventListener("beforeunload", beforeUnload);
});
onBeforeUnmount(() => {
    window.clearInterval(timer);
    window.removeEventListener("beforeunload", beforeUnload);
});
</script>
