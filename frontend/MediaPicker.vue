<template>
    <div>
        <label
            class="form-label"
            :for="id"
        >{{ label }}</label>
        <div class="input-group">
            <input
                :id="id"
                :value="modelValue"
                class="form-control"
                :list="`${id}-list`"
                placeholder="Tapez pour chercher dans les médias…"
                autocomplete="off"
                @input="onInput"
            >
            <button
                v-if="modelValue"
                type="button"
                class="btn btn-outline-secondary"
                aria-label="Aucun jingle"
                @click="emit('update:modelValue', '')"
            >
                ×
            </button>
        </div>
        <datalist :id="`${id}-list`">
            <option
                v-for="f in results"
                :key="f.path"
                :value="f.path"
            >
                {{ f.label }} ({{ Math.round(f.length) }} s)
            </option>
        </datalist>
        <div
            v-if="message"
            class="form-text"
        >
            {{ message }}
        </div>
    </div>
</template>

<script setup>
import { ref } from "vue";

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    modelValue: { type: String, default: "" },
    api: { type: Object, required: true },
});
const emit = defineEmits(["update:modelValue"]);

const results = ref([]);
const message = ref("");
let timer = null;

function onInput(event) {
    const value = event.target.value;
    emit("update:modelValue", value);
    window.clearTimeout(timer);
    if (value.trim().length < 2) {
        results.value = [];
        message.value = "";
        return;
    }
    timer = window.setTimeout(async () => {
        try {
            results.value = (await props.api.mediaSearch(value.trim())).files;
            message.value = results.value.length ? "" : "Aucun fichier trouvé dans les médias.";
        } catch (e) {
            message.value = e.message;
        }
    }, 300);
}
</script>
