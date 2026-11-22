<template>
    <div
        v-if="total > 0"
        class="d-flex flex-wrap align-items-center gap-2"
    >
        <small class="text-body-secondary me-auto">
            {{ first }}–{{ last }} sur {{ total }}
        </small>
        <select
            v-if="perPageOptions.length > 1"
            class="form-select form-select-sm w-auto"
            :value="perPage"
            :aria-label="`${label} : nombre par page`"
            @change="emit('update:perPage', Number($event.target.value))"
        >
            <option
                v-for="n in perPageOptions"
                :key="n"
                :value="n"
            >
                {{ n }} par page
            </option>
        </select>
        <nav
            v-if="pages > 1"
            :aria-label="label"
        >
            <ul class="pagination pagination-sm mb-0">
                <li
                    class="page-item"
                    :class="{ disabled: page <= 1 }"
                >
                    <button
                        type="button"
                        class="page-link"
                        aria-label="Page précédente"
                        :disabled="page <= 1"
                        @click="go(page - 1)"
                    >
                        ‹
                    </button>
                </li>
                <li
                    v-for="(p, i) in items"
                    :key="i"
                    class="page-item"
                    :class="{ active: p === page, disabled: p === null }"
                >
                    <span
                        v-if="p === null"
                        class="page-link"
                    >…</span>
                    <button
                        v-else
                        type="button"
                        class="page-link"
                        :aria-current="p === page ? 'page' : null"
                        @click="go(p)"
                    >
                        {{ p }}
                    </button>
                </li>
                <li
                    class="page-item"
                    :class="{ disabled: page >= pages }"
                >
                    <button
                        type="button"
                        class="page-link"
                        aria-label="Page suivante"
                        :disabled="page >= pages"
                        @click="go(page + 1)"
                    >
                        ›
                    </button>
                </li>
            </ul>
        </nav>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { pageItems } from "./helpers.js";

const props = defineProps({
    page: { type: Number, required: true },
    perPage: { type: Number, required: true },
    total: { type: Number, required: true },
    label: { type: String, default: "Pages" },
    perPageOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:page", "update:perPage"]);

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
const first = computed(() => Math.min(props.total, (props.page - 1) * props.perPage + 1));
const last = computed(() => Math.min(props.total, props.page * props.perPage));
const items = computed(() => pageItems(props.page, pages.value));

function go(p) {
    if (p >= 1 && p <= pages.value && p !== props.page) emit("update:page", p);
}
</script>
