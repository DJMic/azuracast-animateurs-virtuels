import { test } from "node:test";
import assert from "node:assert/strict";
import { merge } from "../bin/compose-override.mjs";
import { contextVariables, describeSlot, findOverlaps } from "../frontend/helpers.js";

const OTHER = `# Mon fichier
services:
  web:
    environment:
      AZURACAST_PLUGIN_MODE: "true"
      COMPOSER_PLUGIN_MODE: "true"
    volumes:
      - ./plugins/azuracast-show-downloader:/var/azuracast/www/plugins/azuracast-show-downloader:ro
`;

test("ajoute les deux montages sans toucher aux autres plugins", () => {
    const out = merge(OTHER);
    assert.match(out, /# Mon fichier/);
    assert.match(out, /azuracast-show-downloader:ro/);
    assert.match(out, /\.\/plugins\/azuracast-animateurs-virtuels:\/var\/azuracast\/www\/plugins\/azuracast-animateurs-virtuels:ro/);
    assert.match(out, /\.\/plugins\/azuracast-animateurs-virtuels\/data:\/var\/azuracast\/plugin-data\/animateurs-virtuels:rw/);
});

test("idempotent", () => {
    const once = merge(OTHER);
    assert.equal(merge(once), once);
});

test("fichier absent : créé avec le mode plugin", () => {
    const out = merge("");
    assert.match(out, /AZURACAST_PLUGIN_MODE: "true"/);
    assert.match(out, /COMPOSER_PLUGIN_MODE: "true"/);
});

test("désinstallation : retire seulement ce plugin", () => {
    const out = merge(merge(OTHER), true);
    assert.doesNotMatch(out, /animateurs-virtuels/);
    assert.match(out, /azuracast-show-downloader:ro/);
    assert.match(out, /AZURACAST_PLUGIN_MODE/);
});

test("résumé d'une tranche", () => {
    assert.equal(
        describeSlot({ days: [1, 2, 3, 4, 5], start: "06:00", end: "09:00", every_n: 4, times: ["07:00"] }),
        "Lun–Ven 06:00–09:00 (toutes les 4 chansons + à 07:00)",
    );
});

test("chevauchement de tranches entre animateurs", () => {
    const slot = (days, start, end) => ({ days, start, end, every_n: 3, times: [] });
    assert.deepEqual(findOverlaps([
        { name: "Léa", enabled: true, slots: [slot([1], "06:00", "09:00")] },
        { name: "Max", enabled: true, slots: [slot([1], "08:00", "10:00")] },
    ]), ["Léa et Max"]);
    assert.deepEqual(findOverlaps([
        { name: "Léa", enabled: true, slots: [slot([7], "22:00", "02:00")] },
        { name: "Max", enabled: true, slots: [slot([1], "01:00", "05:00")] },
    ]), ["Léa et Max"]);
    assert.deepEqual(findOverlaps([
        { name: "Léa", enabled: true, slots: [slot([1], "06:00", "09:00")] },
        { name: "Max", enabled: true, slots: [slot([1], "09:00", "12:00")] },
    ]), []);
});

test("variables interdites dans un prompt intemporel", () => {
    assert.deepEqual(contextVariables("Tu es {animateur} sur {station}"), []);
    assert.deepEqual(contextVariables("Il est {HEURE}, {moment} {heure}"), ["heure", "moment"]);
});
