export const DAYS = [
    {value: 1, short: "Lun", label: "lundi"},
    {value: 2, short: "Mar", label: "mardi"},
    {value: 3, short: "Mer", label: "mercredi"},
    {value: 4, short: "Jeu", label: "jeudi"},
    {value: 5, short: "Ven", label: "vendredi"},
    {value: 6, short: "Sam", label: "samedi"},
    {value: 7, short: "Dim", label: "dimanche"},
];

export const USAGES = [
    {value: "all", label: "Toutes les interventions"},
    {value: "between", label: "Entre deux morceaux"},
    {value: "fixed", label: "Heures fixes"},
];

export const KINDS = {
    between: "Entre deux morceaux",
    fixed: "Heure fixe",
    test: "Test",
    chronicle: "Chronique",
};

export const STATUSES = {
    pending: {label: "En attente", cls: "text-bg-secondary"},
    running: {label: "En cours", cls: "text-bg-info"},
    done: {label: "Prête", cls: "text-bg-success"},
    failed: {label: "Échec", cls: "text-bg-danger"},
    skipped: {label: "Annulée", cls: "text-bg-warning"},
};

export const ELEVENLABS_MODELS = [
    {value: "eleven_v4", label: "Eleven v4 (recommandé : le plus naturel et expressif)"},
    {value: "eleven_v3", label: "Eleven v3 (expressif)"},
    {value: "eleven_multilingual_v2", label: "Multilingual v2 (plus régulier, moins expressif)"},
    {value: "eleven_flash_v2_5", label: "Flash v2.5 (rapide, moins cher)"},
    {value: "eleven_turbo_v2_5", label: "Turbo v2.5"},
];

/** French preset voices of Voxtral 3 (also returned by the "Charger les voix" button). */
export const VOXTRAL_PRESETS = [
    { id: "voice_592", name: "Marie (féminine)" },
    { id: "voice_3137", name: "Manon (féminine)" },
    { id: "voice_589", name: "Léa (féminine)" },
    { id: "voice_3141", name: "Camille (féminine)" },
];

export const VOXTRAL_DEFAULT = {
    mode: "id", voice_id: "", sample_media: "", sample_start: 0, sample_seconds: 15, expressiveness: 0,
};

export const DEEPSEEK_MODELS = ["deepseek-flash", "deepseek-v4-pro", "deepseek-v4-flash"];

export function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

/** "Lun–Ven", "Tous les jours", "Lun, Mer, Sam"… */
export function describeDays(days) {
    const sorted = [...days].sort((a, b) => a - b);
    if (sorted.length === 7) return "Tous les jours";
    if (sorted.length >= 3 && sorted.every((d, i) => i === 0 || d === sorted[i - 1] + 1)) {
        return `${DAYS[sorted[0] - 1].short}–${DAYS[sorted[sorted.length - 1] - 1].short}`;
    }
    return sorted.map((d) => DAYS[d - 1].short).join(", ");
}

export function describeSlot(slot) {
    const parts = [`${describeDays(slot.days)} ${slot.start}–${slot.end}`];
    const rules = [];
    if (slot.every_n > 0) rules.push(`toutes les ${slot.every_n} chansons`);
    if (slot.times.length) rules.push(`à ${slot.times.join(", ")}`);
    if (rules.length) parts.push(`(${rules.join(" + ")})`);
    return parts.join(" ");
}

export function formatDateTime(value) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString("fr-BE", {
        day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit",
    });
}

export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds === "") return "";
    const s = Math.round(Number(seconds));
    return s >= 60 ? `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, "0")} s` : `${s} s`;
}

/** Slots of two hosts overlapping on the same day (warning only: the first host in the list wins). */
export function findOverlaps(hosts) {
    const ranges = [];
    for (const host of hosts) {
        if (!host.enabled) continue;
        for (const slot of host.slots) {
            const [sh, sm] = slot.start.split(":").map(Number);
            const [eh, em] = slot.end.split(":").map(Number);
            const start = sh * 60 + sm;
            let length = (eh * 60 + em - start + 1440) % 1440;
            if (length === 0) length = 1440;
            for (const day of slot.days) {
                const from = (day - 1) * 1440 + start;
                ranges.push({host: host.name, from, to: from + length});
            }
        }
    }
    const week = 7 * 1440;
    const overlaps = new Set();
    for (let i = 0; i < ranges.length; i++) {
        for (let j = i + 1; j < ranges.length; j++) {
            const a = ranges[i];
            const b = ranges[j];
            if (a.host === b.host) continue;
            for (const shift of [-week, 0, week]) {
                if (a.from < b.to + shift && b.from + shift < a.to) {
                    overlaps.add([a.host, b.host].sort().join(" et "));
                }
            }
        }
    }
    return [...overlaps];
}

/** Variables that make a prompt depend on the moment (a reusable prompt cannot use them). */
export const CONTEXT_VARIABLES = [
    "heure", "jour", "date", "moment",
    "titre_precedent", "artiste_precedent", "titre_suivant", "artiste_suivant",
];

export function contextVariables(text) {
    const found = [...(text || "").matchAll(/\{([a-z_]+)\}/gi)].map((m) => m[1].toLowerCase());
    return [...new Set(found.filter((v) => CONTEXT_VARIABLES.includes(v)))];
}

export function formatTimestamp(seconds) {
    return seconds ? formatDateTime(new Date(seconds * 1000).toISOString()) : "";
}

export function describeSchedule(schedule) {
    if (!schedule.times.length) return "Manuelle uniquement";
    return `${describeDays(schedule.days)} à ${schedule.times.join(", ")}`;
}

export function formatNumber(value) {
    return Number(value || 0).toLocaleString("fr-BE");
}

export function formatDay(seconds) {
    return seconds
        ? new Date(seconds * 1000).toLocaleDateString("fr-BE", { day: "numeric", month: "long" })
        : "";
}
