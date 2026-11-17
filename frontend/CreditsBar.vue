<template>
    <div class="small">
        <div
            v-if="credits.error"
            class="text-warning-emphasis"
        >
            Crédits illisibles : {{ credits.error }}
        </div>
        <template v-else-if="credits.limit !== null">
            <div
                class="progress"
                role="progressbar"
                :aria-valuenow="percent"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-label="`Crédits utilisés : ${percent} %`"
                style="height: 0.6rem"
            >
                <div
                    class="progress-bar"
                    :class="credits.exhausted ? 'bg-danger' : (credits.low ? 'bg-warning' : 'bg-success')"
                    :style="{ width: percent + '%' }"
                />
            </div>
            <div class="mt-1">
                <strong :class="credits.exhausted ? 'text-danger' : (credits.low ? 'text-warning-emphasis' : '')">
                    {{ formatNumber(credits.remaining) }} crédits restants
                </strong>
                · {{ formatNumber(credits.used) }} utilisés sur {{ formatNumber(credits.limit) }}
                <template v-if="credits.reset">
                    · remise à zéro le {{ formatDay(credits.reset) }}
                </template>
                <template v-if="credits.tier">
                    · offre « {{ credits.tier }} »
                </template>
            </div>
            <div
                v-if="credits.exhausted"
                class="text-danger"
            >
                Crédits épuisés : les animateurs de ce compte utilisent la bibliothèque (si activée) jusqu'à la remise à
                zéro.
            </div>
            <div
                v-else-if="credits.empty_at"
                class="text-warning-emphasis"
            >
                Au rythme actuel, crédits épuisés vers le {{ formatDay(credits.empty_at) }}.
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { formatDay, formatNumber } from "./helpers.js";

const props = defineProps({
    credits: { type: Object, required: true },
});

const percent = computed(() => (props.credits.limit
    ? Math.min(100, Math.round((props.credits.used / props.credits.limit) * 100))
    : 0));
</script>
