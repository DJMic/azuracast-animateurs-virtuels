<?php

declare(strict_types=1);

// Standalone tests for the logic that does not need AzuraCast: php tests/run.php

foreach (['Plugin', 'Settings', 'Planner', 'PromptBuilder', 'Shell', 'Mixer', 'Store', 'Jobs', 'Http', 'ElevenLabs', 'Library', 'Credits', 'ClipAnnotations', 'ChronicleSettings', 'Weather', 'Sources', 'VoxtralVoice', 'Voxtral', 'Generator'] as $class) {
    require __DIR__ . '/../src/Service/' . $class . '.php';
}

use Plugin\AzuracastAnimateursVirtuels\Service\ChronicleSettings;
use Plugin\AzuracastAnimateursVirtuels\Service\ClipAnnotations;
use Plugin\AzuracastAnimateursVirtuels\Service\Credits;
use Plugin\AzuracastAnimateursVirtuels\Service\ElevenLabs;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Library;
use Plugin\AzuracastAnimateursVirtuels\Service\Mixer;
use Plugin\AzuracastAnimateursVirtuels\Service\Planner;
use Plugin\AzuracastAnimateursVirtuels\Service\PromptBuilder;
use Plugin\AzuracastAnimateursVirtuels\Service\Settings;
use Plugin\AzuracastAnimateursVirtuels\Service\Shell;
use Plugin\AzuracastAnimateursVirtuels\Service\Sources;
use Plugin\AzuracastAnimateursVirtuels\Service\Generator;
use Plugin\AzuracastAnimateursVirtuels\Service\Voxtral;
use Plugin\AzuracastAnimateursVirtuels\Service\VoxtralVoice;
use Plugin\AzuracastAnimateursVirtuels\Service\Weather;

$failures = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        echo "ÉCHEC  $label\n  attendu : " . var_export($expected, true) . "\n  obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "ok     $label\n";
    }
}
function throws(string $label, callable $fn, string $contains = ''): void
{
    try {
        $fn();
        check($label, 'pas d\'exception', 'exception');
    } catch (InvalidArgumentException $e) {
        check($label, $contains === '' || str_contains($e->getMessage(), $contains), true);
    }
}

$tz = new DateTimeZone('Europe/Brussels');
$at = static fn(string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);

// --- Settings ---------------------------------------------------------------------------------
$host = Settings::defaultHost();
$host['name'] = 'Léa';
$host['voice_id'] = '21m00Tcm4TlvDq8ikWAM';
$input = Settings::defaults();
$input['hosts'] = [$host];
$input['deepseek']['api_key'] = 'sk-deepseek-1234';
$input['elevenlabs']['accounts'] = [['id' => '', 'name' => 'Compte A', 'api_key' => 'sk_eleven_9876']];
$input['enabled'] = true;

$saved = Settings::fromInput($input, []);
check('ids attribués', strlen($saved['hosts'][0]['id']) === 12 && strlen($saved['hosts'][0]['slots'][0]['id']) === 12, true);
check('clé conservée', $saved['deepseek']['api_key'], 'sk-deepseek-1234');
$display = Settings::forDisplay($saved);
check('clé masquée', $display['deepseek']['api_key'], Settings::MASK);
check('indice de clé', $display['elevenlabs']['accounts'][0]['api_key_hint'], '…9876');
check('clé de compte masquée', $display['elevenlabs']['accounts'][0]['api_key'], Settings::MASK);
$again = Settings::fromInput($display, $saved);
check('masque = clé inchangée', $again['elevenlabs']['accounts'][0]['api_key'], 'sk_eleven_9876');
check('animateur rattaché au premier compte', $saved['hosts'][0]['account_id'], $saved['elevenlabs']['accounts'][0]['id']);
check('ids stables', $again['hosts'][0]['id'], $saved['hosts'][0]['id']);
$cleared = $display;
$cleared['enabled'] = false;
$cleared['deepseek']['api_key_clear'] = true;
check('suppression de clé', Settings::fromInput($cleared, $saved)['deepseek']['api_key'], '');
$noKey = $input;
$noKey['elevenlabs']['accounts'] = [];
throws('activation sans compte refusée', static fn() => Settings::fromInput($noKey, []), 'compte ElevenLabs');
$noKey['enabled'] = false;
$noKey['elevenlabs']['accounts'] = [['name' => 'Vide', 'api_key' => '']];
throws('compte sans clé refusé', static fn() => Settings::fromInput($noKey, []), '« Vide »');

// --- ElevenLabs accounts --------------------------------------------------------------------------
$legacy = Settings::withDefaults(['elevenlabs' => ['api_key' => 'sk_old_1111', 'model_id' => 'eleven_multilingual_v2'], 'hosts' => [$host]]);
check('migration : ancienne clé = Compte 1', [$legacy['elevenlabs']['accounts'][0]['name'], $legacy['elevenlabs']['accounts'][0]['api_key']], ['Compte 1', 'sk_old_1111']);
check('migration : plus de clé unique', isset($legacy['elevenlabs']['api_key']), false);
check('migration : l\'animateur utilise Compte 1', Settings::account($legacy, $legacy['hosts'][0])['api_key'], 'sk_old_1111');
$legacyDisplay = Settings::forDisplay($legacy);
$legacySaved = Settings::fromInput($legacyDisplay, $legacy);
check('migration : clé gardée au premier enregistrement', $legacySaved['elevenlabs']['accounts'][0]['api_key'], 'sk_old_1111');
$multi = $input;
$multi['elevenlabs']['accounts'] = [
    ['id' => 'acc1acc1', 'name' => 'Compte A', 'api_key' => 'sk_a'],
    ['id' => 'acc2acc2', 'name' => 'Compte B', 'api_key' => 'sk_b'],
];
$multi['hosts'][0]['account_id'] = 'acc2acc2';
$multiSaved = Settings::fromInput($multi, []);
check('animateur sur le compte B', Settings::voiceConfig($multiSaved, $multiSaved['hosts'][0])['api_key'], 'sk_b');
$gone = Settings::forDisplay($multiSaved);
array_pop($gone['elevenlabs']['accounts']);
throws('compte supprimé encore utilisé', static fn() => Settings::fromInput($gone, $multiSaved), 'n\'existe plus');
$six = $input;
$six['elevenlabs']['accounts'] = array_map(static fn(int $i): array => ['name' => 'C' . $i, 'api_key' => 'k' . $i], range(1, 6));
throws('6 comptes refusés', static fn() => Settings::fromInput($six, []), 'Au maximum 5');
$dup = $input;
$dup['elevenlabs']['accounts'] = [['name' => 'X', 'api_key' => 'a'], ['name' => 'x', 'api_key' => 'b']];
throws('noms de comptes en double', static fn() => Settings::fromInput($dup, []), 'Deux comptes');
$reordered = Settings::forDisplay($multiSaved);
$reordered['elevenlabs']['accounts'] = array_reverse($reordered['elevenlabs']['accounts']);
check('clés retrouvées par compte, pas par position', array_column(Settings::fromInput($reordered, $multiSaved)['elevenlabs']['accounts'], 'api_key'), ['sk_b', 'sk_a']);

// --- Chronicle validity -------------------------------------------------------------------------
$vNow = 1_800_000_000;
$vChron = ['valid_hours' => 24, 'playlist_id' => 5];
$vFiles = [
    ['media_id' => 1, 'created_at' => $vNow - 30 * 3600],
    ['media_id' => 2, 'created_at' => $vNow - 25 * 3600, 'withdrawn_at' => $vNow - 3600],
    ['media_id' => 3, 'created_at' => $vNow - 2 * 3600],
];
check('validité : version périmée retirée', ChronicleSettings::expired($vChron, $vFiles, $vNow), [1]);
check('validité : 0 = jamais', ChronicleSettings::expired(['valid_hours' => 0, 'playlist_id' => 5], $vFiles, $vNow), []);
check('validité : sans playlist', ChronicleSettings::expired(['valid_hours' => 24, 'playlist_id' => null], $vFiles, $vNow), []);
check('validité : limite à 720 h', ChronicleSettings::validate(array_replace(ChronicleSettings::defaults(), [
    'name' => 'Horoscope', 'enabled' => false, 'valid_hours' => 9999,
]), [], [])['valid_hours'], 720);
check('validité : 0 par défaut', ChronicleSettings::defaults()['valid_hours'], 0);

// --- Next song after a jingle ----------------------------------------------------------------------
$now = 1_800_000_000;
check('morceau suivant : attente jusqu\'à 45 s avant la diffusion', Generator::resolveDeadline(date(DATE_ATOM, $now + 420), $now), $now + 375);
check('morceau suivant : attente plafonnée à 15 min', Generator::resolveDeadline(date(DATE_ATOM, $now + 3600), $now), $now + 900);
check('morceau suivant : diffusion imminente, pas d\'attente', Generator::resolveDeadline(date(DATE_ATOM, $now + 20), $now) < $now, true);
check('morceau suivant : heure de demande inconnue', Generator::resolveDeadline('', $now) < $now, true);

// --- Mistral Voxtral ------------------------------------------------------------------------------
$vxHost = Settings::defaultHost();
$vxHost['name'] = 'Camille';
$vxHost['provider'] = 'voxtral';
$vxHost['voxtral'] = ['mode' => 'id', 'voice_id' => 'voice_3141', 'expressiveness' => 2.5];
$vxInput = Settings::defaults();
$vxInput['enabled'] = true;
$vxInput['deepseek']['api_key'] = 'sk-deepseek-1234';
$vxInput['hosts'] = [$vxHost];
throws('voxtral : clé Mistral exigée', static fn() => Settings::fromInput($vxInput, []), 'clé API Mistral');
$vxInput['voxtral']['api_key'] = 'mistral-key-5678';
$vxSaved = Settings::fromInput($vxInput, []);
check('voxtral : activable sans compte ElevenLabs', $vxSaved['enabled'] && Settings::isReady($vxSaved), true);
check('voxtral : animateur sans compte ElevenLabs', $vxSaved['hosts'][0]['account_id'], '');
check('voxtral : clé masquée', Settings::forDisplay($vxSaved)['voxtral']['api_key'], Settings::MASK);
check('voxtral : masque = clé inchangée', Settings::fromInput(Settings::forDisplay($vxSaved), $vxSaved)['voxtral']['api_key'], 'mistral-key-5678');
$vxSpeech = Settings::speech($vxSaved, $vxSaved['hosts'][0]);
check('voxtral : voix résolue', [$vxSpeech['provider'], $vxSpeech['model'], $vxSpeech['api_key'], $vxSpeech['voxtral']['voice_id']], ['voxtral', 'voxtral-mini-tts-3-0', 'mistral-key-5678', 'voice_3141']);
check('voxtral : animateur planifiable', Settings::hasVoice($vxSaved['hosts'][0]), true);
check('elevenlabs : voix résolue', Settings::speech($saved, $saved['hosts'][0])['config']['api_key'], 'sk_eleven_9876');
$noVoice = $vxInput;
$noVoice['hosts'][0]['voxtral'] = ['mode' => 'sample', 'sample_media' => ''];
throws('voxtral : fichier de référence exigé', static fn() => Settings::fromInput($noVoice, []), 'voix de référence');
$noVoice['hosts'][0]['voxtral'] = ['mode' => 'id', 'voice_id' => 'bad id!'];
throws('voxtral : identifiant invalide', static fn() => Settings::fromInput($noVoice, []), 'invalide');
$mixed = $vxInput;
$mixed['hosts'][] = $host;
throws('elevenlabs : compte exigé pour l\'autre animateur', static fn() => Settings::fromInput($mixed, []), 'ajoutez un compte ElevenLabs');

$v = VoxtralVoice::validate(['mode' => 'sample', 'sample_media' => 'Voix/lea.mp3', 'sample_seconds' => 90, 'expressiveness' => 0.5], 'x');
check('voxtral : extrait limité à 30 s, expressivité par défaut', [$v['sample_seconds'], $v['expressiveness']], [30, 0.0]);
throws('voxtral : chemin de média refusé', static fn() => VoxtralVoice::validate(['sample_media' => '../etc/passwd'], 'x'), 'invalide');
check('voxtral : requête avec voix', VoxtralVoice::payload('voxtral-mini-tts-3-0', $vxSaved['hosts'][0]['voxtral'], 'Bonjour'), [
    'model' => 'voxtral-mini-tts-3-0', 'input' => 'Bonjour', 'response_format' => 'mp3', 'voice_id' => 'voice_3141', 'expressiveness' => 2.5,
]);
check('voxtral : requête par clonage sans voice_id', array_key_exists('voice_id', VoxtralVoice::payload('', $v, 'Salut')), false);
check('voxtral : audio base64 décodé', Voxtral::audio(json_encode(['audio_data' => base64_encode(str_repeat('A', 600))]), 'application/json'), str_repeat('A', 600));
check('voxtral : audio brut accepté', strlen(Voxtral::audio(str_repeat("\xFF", 800), 'audio/mpeg')), 800);
try {
    Voxtral::audio('{"id":"x"}', 'application/json');
    check('voxtral : réponse sans audio refusée', false, true);
} catch (RuntimeException $e) {
    check('voxtral : réponse sans audio refusée', str_contains($e->getMessage(), 'sans audio'), true);
}
check('voxtral : liste des voix', Voxtral::parseVoices(['data' => [
    ['id' => 'voice_592', 'slug' => 'marie', 'name' => 'Marie', 'type' => 'preset', 'languages' => ['fr']],
    ['id' => '7f0c-uuid', 'name' => 'Ma voix', 'type' => 'custom', 'languages' => []],
]]), [
    ['id' => 'voice_592', 'name' => 'Marie', 'type' => 'preset', 'languages' => ['fr']],
    ['id' => '7f0c-uuid', 'name' => 'Ma voix', 'type' => 'custom', 'languages' => []],
]);
$long = str_repeat('Une phrase de chronique assez longue pour remplir. ', 30) . '[emotion=happy]Et une bonne nouvelle pour finir ! ' . str_repeat('Encore une phrase de suite ici. ', 40);
$parts = VoxtralVoice::split($long, 600);
check('voxtral : texte long découpé', count($parts) > 2 && max(array_map('mb_strlen', $parts)) <= 600 + 20, true);
check('voxtral : rien de perdu au découpage', str_replace(' ', '', implode('', array_map(static fn(string $p): string => (string)preg_replace('/^(\[(?:emotion|pace)=[a-z-]+\])+/', '', $p), $parts))), str_replace(' ', '', $long));
check('voxtral : émotion reportée sur la partie suivante', str_starts_with($parts[count($parts) - 1], '[emotion=happy]'), true);
check('voxtral : texte court non découpé', VoxtralVoice::split('Bonjour !'), ['Bonjour !']);
check('voxtral : signature différente d\'ElevenLabs', Library::voiceSignature($vxSaved, $vxSaved['hosts'][0]) !== Library::voiceSignature($saved, $saved['hosts'][0]), true);
$vxOther = $vxSaved['hosts'][0];
$vxOther['voxtral']['voice_id'] = 'voice_592';
check('voxtral : signature liée à la voix', Library::voiceSignature($vxSaved, $vxOther) !== Library::voiceSignature($vxSaved, $vxSaved['hosts'][0]), true);
check('voxtral : nettoyage des balises', PromptBuilder::clean('[warmly] Bonjour à tous ! [laughs] <break time="1s" /> [pace=slow] On ralentit… [emotion=ecstatic] Fin.', 'voxtral-mini-tts-3-0'),
    '[emotion=warm]Bonjour à tous ! [pace=slow]On ralentit… Fin.');
check('voxtral : consignes d\'écriture', str_contains(PromptBuilder::radioGuidelines('voxtral-mini-tts-3-0'), '[emotion='), true);
check('voxtral : modèle de l\'animateur dans le prompt', str_contains(PromptBuilder::messages($vxSaved, $vxSaved['hosts'][0], $vxSaved['hosts'][0]['prompts'][0], [])[0]['content'], '[emotion='), true);
check('elevenlabs : consignes inchangées', str_contains(PromptBuilder::messages($saved, $saved['hosts'][0], $saved['hosts'][0]['prompts'][0], [])[0]['content'], '[warmly]'), true);
$vxChron = ChronicleSettings::defaults();
$vxChron['name'] = 'Agenda';
$vxChron['voice_mode'] = 'own';
$vxChron['provider'] = 'voxtral';
$vxChron['voxtral'] = ['mode' => 'id', 'voice_id' => 'voice_592'];
$vxChron['sources'] = [['type' => 'url', 'url' => 'https://www.example.org/agenda']];
throws('voxtral : chronique sans clé Mistral', static fn() => ChronicleSettings::validate($vxChron, [], []), 'clé API Mistral');
$vxChronValid = ChronicleSettings::validate($vxChron, [], [], true);
check('voxtral : chronique avec voix propre', ChronicleSettings::voice($vxSaved, $vxChronValid)['voxtral']['voice_id'], 'voice_592');
$vxChron['voice_mode'] = 'host';
$vxChron['host_id'] = $vxSaved['hosts'][0]['id'];
check('voxtral : chronique avec la voix de l\'animateur', ChronicleSettings::voice($vxSaved, ChronicleSettings::validate($vxChron, [$vxSaved['hosts'][0]['id']], [], true))['provider'], 'voxtral');
check('voxtral : extrait de référence', array_slice(Mixer::extractArguments('ffmpeg', 'in.mp3', 'out.wav', 12.5, 15), 4, 4), ['-ss', '12.5', '-i', 'in.mp3']);

// --- Credits ----------------------------------------------------------------------------------------
check('lecture des crédits', ElevenLabs::parseSubscription(['tier' => 'free', 'character_count' => 4230, 'character_limit' => 10000, 'next_character_count_reset_unix' => 1764547200]), ['used' => 4230, 'limit' => 10000, 'reset' => 1764547200, 'tier' => 'free']);
$reset = (new DateTimeImmutable('2026-12-01 00:00:00 UTC'))->getTimestamp();
$now = (new DateTimeImmutable('2026-11-16 00:00:00 UTC'))->getTimestamp(); // 15 days into a 30-day period
$acc = ['id' => 'acc1acc1', 'name' => 'Compte A'];
$row = Credits::describe($acc, ['used' => 6000, 'limit' => 10000, 'reset' => $reset, 'checked_at' => $now, 'error' => ''], 500, $now);
check('crédits restants', $row['remaining'], 4000);
check('épuisement estimé avant la remise à zéro', $row['empty_at'] !== null && $row['empty_at'] > $now && $row['empty_at'] < $reset, true);
check('épuisement : environ 10 jours', round(($row['empty_at'] - $now) / 86400), 10.0);
$row = Credits::describe($acc, ['used' => 3000, 'limit' => 10000, 'reset' => $reset, 'checked_at' => $now, 'error' => ''], 500, $now);
check('pas d\'épuisement si le rythme tient', $row['empty_at'], null);
$row = Credits::describe($acc, ['used' => 9700, 'limit' => 10000, 'reset' => $reset, 'checked_at' => $now, 'error' => ''], 500, $now);
check('seuil bas', [$row['low'], $row['exhausted']], [true, false]);
$row = Credits::describe($acc, ['used' => 10000, 'limit' => 10000, 'reset' => $reset, 'checked_at' => $now, 'error' => ''], 500, $now);
check('épuisé', [$row['low'], $row['exhausted'], $row['remaining']], [false, true, 0]);
check('erreur de lecture affichée', Credits::describe($acc, ['error' => 'permission manquante', 'checked_at' => $now], 500, $now)['error'], 'permission manquante');
$bad = $input;
$bad['hosts'][0]['slots'][0]['times'] = ['12:00'];
throws('heure fixe hors tranche', static fn() => Settings::fromInput($bad, []), 'en dehors');
$bad = $input;
$bad['hosts'][0]['slots'][0]['every_n'] = 0;
$bad['hosts'][0]['slots'][0]['times'] = [];
throws('tranche sans règle', static fn() => Settings::fromInput($bad, []), 'nombre de morceaux');
$bad = $input;
$bad['hosts'][] = $host;
throws('noms en double', static fn() => Settings::fromInput($bad, []), 'Deux animateurs');
$bad = $input;
$bad['hosts'][0]['voice_id'] = '';
throws('voice id obligatoire', static fn() => Settings::fromInput($bad, []), 'Voice ID');
check('valeurs bornées', Settings::fromInput(array_replace_recursive($input, ['bed' => ['duck_db' => 500]]), [])['bed']['duck_db'], 60.0);
$night = $input;
$night['hosts'][0]['slots'][0] = ['days' => [5], 'start' => '22:00', 'end' => '02:00', 'every_n' => 3, 'times' => ['01:00', '23:00']];
check('heures fixes triées depuis le début', Settings::fromInput($night, [])['hosts'][0]['slots'][0]['times'], ['23:00', '01:00']);
check('anciens réglages complétés', Settings::withDefaults(['bed' => ['duck_db' => 9]])['bed']['intro'], 3.0);

// --- Planner ----------------------------------------------------------------------------------
$settings = Settings::fromInput($input, []);
$lea = $settings['hosts'][0]; // Mon–Fri 06:00–09:00, every 4 songs, at 07:00 and 08:00
check('tranche trouvée (lundi 7h12)', Planner::onAir($settings, $at('2026-11-16 07:12'))['host']['name'] ?? null, 'Léa');
check('hors tranche (lundi 9h00)', Planner::onAir($settings, $at('2026-11-16 09:00')), null);
check('hors jour (samedi)', Planner::onAir($settings, $at('2026-11-14 07:00')), null);

$nightSettings = Settings::fromInput($night, []);
check('nuit : vendredi 23h', Planner::onAir($nightSettings, $at('2026-11-20 23:30')) !== null, true);
check('nuit : samedi 1h (tranche du vendredi)', Planner::onAir($nightSettings, $at('2026-11-21 01:30'))['start']->format('Y-m-d H:i') ?? null, '2026-11-20 22:00');
check('nuit : samedi 23h', Planner::onAir($nightSettings, $at('2026-11-21 23:00')), null);

$twoHosts = $settings;
$max = $lea;
$max['id'] = 'maxmaxmaxmax';
$max['name'] = 'Max';
$twoHosts['hosts'][] = $max;
check('chevauchement : le premier gagne', Planner::onAir($twoHosts, $at('2026-11-16 07:00'))['host']['name'], 'Léa');
$twoHosts['hosts'][0]['enabled'] = false;
check('animateur désactivé ignoré', Planner::onAir($twoHosts, $at('2026-11-16 07:00'))['host']['name'], 'Max');

// Prompt imposed at a fixed time on chosen days.
$imposed = $input;
$imposed['hosts'][0]['prompts'][0]['id'] = 'promptweekend';
$imposed['hosts'][0]['slots'][0]['times'] = ['07:00', '08:00'];
$imposed['hosts'][0]['slots'][0]['fixed_prompts'] = [
    ['time' => '07:00', 'days' => [5], 'prompt_id' => 'promptweekend'],
    ['time' => '08:00', 'days' => [], 'prompt_id' => ''],
];
$impSettings = Settings::fromInput($imposed, []);
$impSlot = $impSettings['hosts'][0]['slots'][0];
check('prompt imposé : enregistré (ligne vide ignorée)', $impSlot['fixed_prompts'], [['time' => '07:00', 'days' => [5], 'prompt_id' => 'promptweekend']]);
check('prompt imposé : vendredi 7h', Planner::fixedPrompt($impSlot, '07:00', 5), 'promptweekend');
check('prompt imposé : lundi 7h, au hasard', Planner::fixedPrompt($impSlot, '07:00', 1), null);
check('prompt imposé : vendredi 8h, au hasard', Planner::fixedPrompt($impSlot, '08:00', 5), null);
[$impDecision] = Planner::decide(['slot' => null], Planner::onAir($impSettings, $at('2026-11-20 07:01')), $at('2026-11-20 07:01'), true);
check('prompt imposé : dans la décision du vendredi', $impDecision['prompt_id'], 'promptweekend');
$bad = $imposed;
$bad['hosts'][0]['slots'][0]['fixed_prompts'] = [['time' => '09:30', 'days' => [], 'prompt_id' => 'promptweekend']];
throws('prompt imposé : heure hors des heures fixes', static fn() => Settings::fromInput($bad, []), 'pas une des heures fixes');
$bad['hosts'][0]['slots'][0]['fixed_prompts'] = [['time' => '07:00', 'days' => [], 'prompt_id' => 'inconnu123']];
throws('prompt imposé : prompt inconnu', static fn() => Settings::fromInput($bad, []), 'n\'existe plus');
$bad['hosts'][0]['slots'][0]['fixed_prompts'] = [
    ['time' => '07:00', 'days' => [], 'prompt_id' => 'promptweekend'],
    ['time' => '07:00', 'days' => [5], 'prompt_id' => 'promptweekend'],
];
throws('prompt imposé : deux règles le même jour', static fn() => Settings::fromInput($bad, []), 'même jour');
$all = $imposed;
$all['hosts'][0]['slots'][0]['fixed_prompts'] = [['time' => '07:00', 'days' => [1, 2, 3, 4, 5], 'prompt_id' => 'promptweekend']];
check('prompt imposé : tous les jours de la tranche = []', Settings::fromInput($all, [])['hosts'][0]['slots'][0]['fixed_prompts'][0]['days'], []);

// Every 4 songs: songs 1-4 then an intervention before the 5th.
$state = [];
$decisions = [];
$t = $at('2026-11-16 06:05');
for ($i = 1; $i <= 9; $i++) {
    $onAir = Planner::onAir($settings, $t);
    [$decision, $state] = Planner::decide($state, $onAir, $t, true);
    $decisions[] = $decision['kind'] ?? '-';
    $t = $t->modify('+3 minutes');
}
check('toutes les 4 chansons', implode(',', $decisions), '-,-,-,-,between,-,-,-,between');

[$d, $s2] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-16 06:40')), $at('2026-11-16 06:40'), false);
check('jingle : pas compté', [$d, $s2['count']], [null, $state['count']]);

// Fixed time 07:00: aired at the first queue item starting at or after 07:00, once.
$state = ['slot' => null];
[$d1, $state] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-16 06:58')), $at('2026-11-16 06:58'), true);
[$d2, $state] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-16 07:01')), $at('2026-11-16 07:01'), true);
[$d3, $state] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-16 07:04')), $at('2026-11-16 07:04'), true);
check('heure fixe : avant', $d1, null);
check('heure fixe : déclenchée', $d2, ['kind' => 'fixed', 'time' => '07:00', 'prompt_id' => null]);
check('heure fixe : une seule fois', $d3, null);
check('heure fixe : compteur remis à 1', $state['count'], 2);
[$late, $state] = Planner::decide(['slot' => null], Planner::onAir($settings, $at('2026-11-16 07:20')), $at('2026-11-16 07:20'), true);
check('heure fixe : trop tard (> 15 min)', $late, null);
[$nextDay] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-17 07:02')), $at('2026-11-17 07:02'), true);
check('heure fixe : le lendemain à nouveau', $nextDay['kind'] ?? null, 'fixed');

// Music / jingle / music…: the intervention comes right after the Nth song, before the jingle.
$every2 = $settings;
$every2['hosts'][0]['slots'][0]['every_n'] = 2;
$every2['hosts'][0]['slots'][0]['times'] = [];
$state = [];
$seq = [];
$t = $at('2026-11-16 06:05');
$prevMusic = false;
foreach (['M', 'J', 'M', 'J', 'M', 'J', 'M', 'J', 'M'] as $item) {
    $isMusicItem = $item === 'M';
    [$d, $state] = Planner::decide($state, Planner::onAir($every2, $t), $t, $isMusicItem, $prevMusic);
    if ($d !== null) {
        $seq[] = 'V';
    }
    $seq[] = $item;
    $prevMusic = $isMusicItem;
    $t = $t->modify('+2 minutes');
}
check('intervention avant le jingle', implode('', $seq), 'MJMVJMJMVJM');
[$d, $s2] = Planner::decide(['slot' => null], Planner::onAir($settings, $at('2026-11-16 07:01')), $at('2026-11-16 07:01'), true, true, false);
[$d2] = Planner::decide($s2, Planner::onAir($settings, $at('2026-11-16 07:04')), $at('2026-11-16 07:04'), true, true, true);
check('heure fixe reportée si pas le temps de générer', [$d, $d2['kind'] ?? null], [null, 'fixed']);
$state = ['slot' => null];
$t = $at('2026-11-16 06:05');
foreach ([true, true, true, true] as $m) {
    [, $state] = Planner::decide($state, Planner::onAir($settings, $t), $t, $m);
}
[$late1, $state] = Planner::decide($state, Planner::onAir($settings, $t), $t, true, true, false);
[$late2] = Planner::decide($state, Planner::onAir($settings, $t), $t, true, true, true);
check('entre deux morceaux reporté si pas le temps', [$late1, $late2['kind'] ?? null], [null, 'between']);

$usageHost = ['prompts' => [
    ['id' => 'a', 'name' => 'A', 'usage' => 'between', 'enabled' => true, 'text' => 'a'],
    ['id' => 'b', 'name' => 'B', 'usage' => 'fixed', 'enabled' => true, 'text' => 'b'],
    ['id' => 'c', 'name' => 'C', 'usage' => 'all', 'enabled' => false, 'text' => 'c'],
]];
$picked = [];
for ($i = 0; $i < 30; $i++) {
    $picked[Planner::pickPrompt($usageHost, 'fixed')['id']] = true;
}
check('prompt réservé aux heures fixes', array_keys($picked), ['b']);
check('prompt imposé', Planner::pickPrompt($usageHost, 'test', 'c')['id'], 'c');
check('repli sur un autre usage', Planner::pickPrompt(['prompts' => [$usageHost['prompts'][0]]], 'fixed')['id'], 'a');

// --- PromptBuilder ----------------------------------------------------------------------------
$vars = PromptBuilder::variables($lea, 'Radio Test', 'français', $at('2026-11-16 07:30'), ['title' => 'Song A', 'artist' => 'Artist A'], []);
check('variable heure', $vars['heure'], '7h30');
check('variable jour/date', $vars['jour'] . ' ' . $vars['date'], 'lundi 16 novembre');
check('variable moment', $vars['moment'], 'matin');
check('variable mots', $vars['mots'], '50');
check('heure pile', PromptBuilder::variables($lea, 'R', 'fr', $at('2026-11-16 18:00'))['heure'], '18h');
check('remplacement', PromptBuilder::fill('{ANIMATEUR} sur {station} {inconnue}', $vars), 'Léa sur Radio Test {inconnue}');
check('valeur manquante', PromptBuilder::fill('{titre_suivant}', $vars), PromptBuilder::MISSING);
$messages = PromptBuilder::messages($settings, array_replace($lea, ['personality' => 'Drôle.']), $lea['prompts'][0], $vars, ['Bonjour hier']);
check('messages système + demande', array_column($messages, 'role'), ['system', 'user']);
check('personnalité ajoutée', str_contains($messages[0]['content'], 'Drôle.'), true);
check('anti-répétition', str_contains($messages[0]['content'], '- Bonjour hier'), true);
check('consigne info manquante', str_contains($messages[0]['content'], 'non disponible, n\'en parle pas'), true);
check('nettoyage', PromptBuilder::clean("<think>x</think>**« Bonjour ! »** (rires) [joyeux] Il est 7h 🎉"), 'Bonjour ! » Il est 7h');
check('nettoyage v3 garde les balises', PromptBuilder::clean('[Warmly] Salut', 'eleven_v3'), '[warmly] Salut');
check('nettoyage v4 : balises audio gardées, pauses SSML retirées', PromptBuilder::clean('[excited] Allez <break time="0.5s"/> on y va [souriant] [laughs] [softly] [cheerfully] !', 'eleven_v4'), '[excited] Allez on y va [souriant] [laughs] !');
check('nettoyage v4 : pas de texte libre entre crochets', PromptBuilder::clean('[ton enjoué et complice, 2 secondes] Salut', 'eleven_v4'), 'Salut');
check('nettoyage v2 : pauses normalisées, 2 maximum', PromptBuilder::clean('Bon <break time="500ms"/> allez <break time = \'9s\' /> et <break time="1s"/> fin [warmly]', 'eleven_multilingual_v2'), 'Bon <break time="0.5s" /> allez <break time="3s" /> et fin');
check('nettoyage : autres balises retirées', PromptBuilder::clean('<speak>Salut <emphasis>toi</emphasis></speak>', 'eleven_multilingual_v2'), 'Salut toi');
check('coupure à la phrase', PromptBuilder::clean(str_repeat('Phrase courte. ', 30), '', 100), trim(str_repeat('Phrase courte. ', 6)));
check('écriture radio v4 : balises audio', str_contains(PromptBuilder::radioGuidelines('eleven_v4'), '[warmly]'), true);
check('écriture radio v2 : balise de pause', str_contains(PromptBuilder::radioGuidelines('eleven_multilingual_v2'), '<break time='), true);
$radio = Settings::fromInput($input, []);
check('écriture radio activée par défaut', str_contains(PromptBuilder::messages($radio, $lea, $lea['prompts'][0], $vars)[0]['content'], 'Écris pour l\'oreille'), true);
$radio['radio_style'] = false;
check('écriture radio désactivable', str_contains(PromptBuilder::messages($radio, $lea, $lea['prompts'][0], $vars)[0]['content'], 'Écris pour l\'oreille'), false);
check('modèle par défaut : Eleven v4', Settings::defaults()['elevenlabs']['model_id'], 'eleven_v4');

// --- ElevenLabs payload --------------------------------------------------------------------------
$payload = ElevenLabs::payload(['model_id' => 'eleven_v3', 'language_code' => 'fr'], $lea['voice'], 'Salut');
check('v3 : stabilité arrondie', $payload['voice_settings']['stability'], 0.5);
check('v3 : code langue', $payload['language_code'] ?? null, 'fr');
check('multilingual v2 : pas de code langue', isset(ElevenLabs::payload(['model_id' => 'eleven_multilingual_v2', 'language_code' => 'fr'], $lea['voice'], 'x')['language_code']), false);

// --- Mixer -------------------------------------------------------------------------------------
$args = Mixer::arguments('ffmpeg', 'v.mp3', 10.0, 'bed.mp3', 0.0, Settings::defaults()['bed'], Settings::defaults()['output'], 'o.mp3');
$graph = $args[array_search('-filter_complex', $args, true) + 1];
check('fond coupé à intro+voix+sortie', str_contains($graph, 'atrim=0:16'), true);
check('voix décalée de l\'intro', str_contains($graph, 'adelay=3000:all=1'), true);
check('baisse de 15 dB', str_contains($graph, '0.178'), true);
check('fondu de sortie', str_contains($graph, 'afade=t=out:st=14:d=2'), true);
check('fond bouclé si trop court', in_array('-stream_loop', $args, true), true);
$voiceOnly = Mixer::arguments('ffmpeg', 'v.mp3', 10.0, null, 0.0, Settings::defaults()['bed'], ['normalize' => false, 'bitrate' => 128], 'o.mp3');
check('voix seule sans normalisation', str_contains(implode(' ', $voiceOnly), 'loudnorm'), false);

// --- Jobs (temporary folder) -----------------------------------------------------------------------
$root = sys_get_temp_dir() . '/av-tests-' . bin2hex(random_bytes(4));
mkdir($root);
$jobs = new Jobs($root);
$job = $jobs->create(7, $at('2026-11-16 07:00'), ['host_id' => 'x', 'kind' => 'between']);
check('id de l\'intervention', Jobs::isValidId($job['id']) && str_starts_with($job['id'], '20261116-0700'), true);
check('chemin du clip', $job['path'], $root . '/7/clips/' . $job['id'] . '.mp3');
check('compte du jour', $jobs->countToday(7, $at('2026-11-16 22:00')), 1);
check('compte du lendemain', $jobs->countToday(7, $at('2026-11-17 01:00')), 0);
$job['status'] = 'done';
$job['text'] = 'Texte 1';
$jobs->save(7, $job);
check('textes récents', $jobs->recentTexts(7, 'x', 3), ['Texte 1']);
check('verrou', $jobs->withLock(7, $job['id'], static fn() => 'dans le verrou'), 'dans le verrou');
$jobs->updateState(7, static fn(array $s): array => ['count' => 3] + $s);
check('état', $jobs->readState(7)['count'], 3);
touch($root . '/7/jobs/' . $job['id'] . '.json', time() - 10 * 86400);
check('nettoyage après la durée de conservation', $jobs->cleanup(7, 7), 1);
check('historique vide', $jobs->list(7), []);
exec('rm -rf ' . escapeshellarg($root));

// --- Library -------------------------------------------------------------------------------------
check('prompt intemporel accepté', Library::contextVariables('Rappelle que tu es {animateur} sur {station}'), []);
check('variables de contexte détectées', Library::contextVariables('Il est {HEURE}, on écoute {titre_suivant}'), ['heure', 'titre_suivant']);
$timeless = $input;
$timeless['hosts'][0]['prompts'][] = ['name' => 'X', 'usage' => 'between', 'enabled' => true, 'timeless' => true, 'text' => 'Ce {moment} sur {station}'];
throws('prompt réutilisable avec {moment} refusé', static fn() => Settings::fromInput($timeless, []), '{moment}');
check('prompt réutilisable par défaut', Settings::defaultHost()['prompts'][2]['timeless'], true);
check('réglages de bibliothèque par défaut', Settings::fromInput($input, [])['hosts'][0]['library']['min_size'], 20);
foreach ([
    'Bonjour à tous, restez avec nous !' => 'bonjour',
    'Belle soirée sur Radio Test.' => 'soirée',
    'On se retrouve demain.' => 'demain',
    'Il est 7h30 sur Radio Test.' => '7h30',
    'Le morceau suivant va vous plaire.' => 'morceau suivant',
    'Un été plein de musique.' => null,
    'Ça a été un plaisir, restez à l\'écoute de Radio Test, la musique qui fait du bien.' => null,
    'Merci d’être là, vous écoutez Radio Test.' => null,
    'Mais quelle ambiance sur Radio Test !' => null,
] as $text => $expected) {
    check('filtre intemporel : ' . $text, Library::timeMarker($text), $expected);
}
$config = ['enabled' => true, 'min_size' => 20, 'max_share' => 60] + Library::DEFAULTS;
check('réutilisation : 0 sous le minimum', Library::reuseShare($config, 19), 0.0);
check('réutilisation : faible au minimum', Library::reuseShare($config, 20) > 0 && Library::reuseShare($config, 20) < 0.05, true);
check('réutilisation : croissante', Library::reuseShare($config, 40) > Library::reuseShare($config, 25), true);
check('réutilisation : plafond à 3× le minimum', Library::reuseShare($config, 60), 0.6);
check('réutilisation : plafonnée', Library::reuseShare($config, 500), 0.6);
check('réutilisation : désactivée', Library::reuseShare(['enabled' => false] + $config, 500), 0.0);
$sig = Library::voiceSignature($settings, $lea);
$other = $lea;
$other['voice']['speed'] = 1.1;
check('signature : change avec la voix', Library::voiceSignature($settings, $other) !== $sig, true);
$now = 1_800_000_000;
$entries = [
    ['id' => 'a', 'signature' => $sig, 'last_used_at' => $now - 86400],
    ['id' => 'b', 'signature' => $sig, 'last_used_at' => $now - 10 * 86400],
    ['id' => 'c', 'signature' => $sig, 'last_used_at' => null],
    ['id' => 'd', 'signature' => 'autre', 'last_used_at' => null],
];
check('choix : jamais diffusé d\'abord', Library::choose($entries, $sig, $config, $now)['id'], 'c');
check('choix : délai respecté', Library::choose(array_slice($entries, 0, 2), $sig, $config, $now)['id'], 'b');
check('choix : rien de disponible', Library::choose([$entries[0], $entries[3]], $sig, $config, $now), null);
check('choix : secours sans délai', Library::choose([$entries[0], $entries[3]], $sig, $config, $now, true)['id'], 'a');

$root = sys_get_temp_dir() . '/av-lib-' . bin2hex(random_bytes(4));
mkdir($root);
$library = new Library($root);
$voice = $root . '/voice.mp3';
file_put_contents($voice, str_repeat('x', 2000));
$e1 = $library->add(3, 'host00000001', $voice, ['text' => 'Un', 'signature' => $sig], 2);
$e2 = $library->add(3, 'host00000001', $voice, ['text' => 'Deux', 'signature' => $sig], 2);
check('bibliothèque : 2 clips', count($library->entries(3, 'host00000001')), 2);
$taken = $library->take(3, 'host00000001', $sig, ['max_uses' => 2] + $config);
check('bibliothèque : utilisation comptée', [$taken['uses'], $taken['retire']], [1, false]);
check('bibliothèque : le même clip n\'est pas repris (délai)', $library->take(3, 'host00000001', $sig, $config)['id'] !== $taken['id'], true);
check('bibliothèque : plus rien avant le délai', $library->take(3, 'host00000001', $sig, $config), null);
$e3 = $library->add(3, 'host00000001', $voice, ['text' => 'Trois', 'signature' => 'ancienne'], 2);
check('bibliothèque : taille maximale respectée', count($library->entries(3, 'host00000001')), 2);
check('bibliothèque : suppression des clips d\'une ancienne voix', $library->deleteObsolete(3, 'host00000001', $sig), 1);
check('bibliothèque : suppression d\'un clip', $library->delete(3, 'host00000001', $library->entries(3, 'host00000001')[0]['id']), true);
check('bibliothèque : vide', $library->entries(3, 'host00000001'), []);
$library->deleteHost(3, 'host00000001');
check('bibliothèque : animateur supprimé', $library->hosts(3), []);
exec('rm -rf ' . escapeshellarg($root));

$statsRoot = sys_get_temp_dir() . '/av-stats-' . bin2hex(random_bytes(4));
mkdir($statsRoot);
$statsJobs = new Jobs($statsRoot);
$day = $at('2026-11-16 08:00');
foreach ([['status' => 'done'], ['status' => 'done', 'reused' => true], ['status' => 'failed'], ['status' => 'skipped'], ['status' => 'pending', 'reuse_only' => true]] as $j) {
    $statsJobs->create(1, $day, $j);
}
check('limite : les clips réutilisés ne comptent pas', $statsJobs->todayStats(1, $day), ['generated' => 2, 'reused' => 1]);
$statsJobs->create(1, $at('2026-11-16 09:00'), ['status' => 'done', 'kind' => 'chronicle']);
$statsJobs->create(1, $at('2026-11-16 10:00'), ['status' => 'done', 'kind' => 'between']);
$p1 = $statsJobs->page(1, 1, 3);
check('historique : page 1, plus récente d\'abord', [count($p1['rows']), $p1['total'], $p1['page'], $p1['rows'][0]['kind']], [3, 7, 1, 'between']);
$p3 = $statsJobs->page(1, 3, 3);
check('historique : dernière page', [count($p3['rows']), $p3['page']], [1, 3]);
check('historique : page au-delà de la fin', $statsJobs->page(1, 9, 3)['page'], 3);
$chron = $statsJobs->page(1, 1, 10, 'chronicles');
check('historique : filtre chroniques', [$chron['total'], $chron['rows'][0]['kind']], [1, 'chronicle']);
check('historique : filtre interventions', $statsJobs->page(1, 1, 10, 'interventions')['total'], 6);
check('historique : vide', Jobs::slice(0, 4, 25), ['page' => 1, 'offset' => 0]);
exec('rm -rf ' . escapeshellarg($statsRoot));

// --- Liquidsoap annotations ------------------------------------------------------------------------
// Keys AzuraCast lets through (AnnotateNextSong::ALLOWED_ANNOTATIONS); any other key is dropped.
$allowed = ['title', 'artist', 'duration', 'song_id', 'media_id', 'playlist_id', 'jingle_mode', 'request_id', 'sq_id',
    'liq_amplify', 'azuracast_autocue', 'azuracast_cache_key', 'autocue_cue_in', 'autocue_cue_out', 'autocue_fade_in',
    'autocue_fade_out', 'autocue_start_next'];
$shown = ClipAnnotations::build('Léa', 'Radio Test', 26.4, false, 2.0);
check('annotations : uniquement des clés acceptées par AzuraCast', array_diff(array_keys($shown + ['sq_id' => 1]), $allowed), []);
check('annotations : titre et artiste affichés', [$shown['title'], $shown['artist'], isset($shown['jingle_mode'])], ['Léa', 'Radio Test', false]);
check('annotations : calage AutoCue fourni', [$shown['azuracast_autocue'], $shown['autocue_cue_in'], $shown['autocue_cue_out']], ['true', 0.0, 26.4]);
check('annotations : morceau suivant au fondu du fond', $shown['autocue_start_next'], 24.4);
$hidden = ClipAnnotations::build('Léa', 'Radio Test', 12.0, true);
check('annotations masquées : ni titre ni artiste, mode jingle', [$hidden['title'], $hidden['artist'], $hidden['jingle_mode']], ['', '', 'true']);
check('annotations voix seule : suivant juste avant la fin', $hidden['autocue_start_next'], 11.7);
check('annotations : silence', ClipAnnotations::build('', '', 0.5, true)['autocue_cue_out'], 0.5);

// --- Chronicles -----------------------------------------------------------------------------------
$chron = ChronicleSettings::defaults();
$chron['name'] = 'Infos régionales';
$chron['voice_mode'] = 'host';
$chron['host_id'] = 'leahostid01';
$chron['sources'] = [['type' => 'url', 'url' => 'https://www.example.org/rss.xml'], ['type' => 'weather', 'location' => 'Namur']];
$chron['schedule'] = ['days' => [1, 2, 3, 4, 5], 'times' => ['07:30', '06:30']];
$valid = ChronicleSettings::validate($chron, ['leahostid01'], ['acc1acc1']);
check('chronique : dossier par défaut créé à partir du nom', $valid['folder'], 'Chroniques/Infos regionales');
check('chronique : heures triées', $valid['schedule']['times'], ['06:30', '07:30']);
check('chronique : deux sources', array_column($valid['sources'], 'type'), ['url', 'weather']);
check('chronique : nom de fichier', ChronicleSettings::fileName('Météo & trafic', $at('2026-11-18 06:30')), 'Meteo_trafic_2026-11-18_0630.mp3');
throws('chronique : animateur inconnu', static fn() => ChronicleSettings::validate($chron, ['autre000001'], []), 'animateur');
$own = $chron;
$own['voice_mode'] = 'own';
$own['voice_id'] = '';
throws('chronique : voix propre sans Voice ID', static fn() => ChronicleSettings::validate($own, [], ['acc1acc1']), 'Voice ID');
$own['voice_id'] = 'AbCdEf12345';
check('chronique : voix propre, premier compte par défaut', ChronicleSettings::validate($own, [], ['acc1acc1'])['account_id'], 'acc1acc1');
$bad = $chron;
$bad['sources'] = [['type' => 'url', 'url' => 'ftp://exemple']];
throws('chronique : adresse invalide', static fn() => ChronicleSettings::validate($bad, ['leahostid01'], []), 'adresse');
$bad = $chron;
$bad['sources'] = [];
throws('chronique : au moins une source', static fn() => ChronicleSettings::validate($bad, ['leahostid01'], []), 'source');
$bad = $chron;
$bad['folder'] = 'Chroniques/../Secret';
throws('chronique : dossier avec ..', static fn() => ChronicleSettings::validate($bad, ['leahostid01'], []), 'dossier');
$bad = $chron;
$bad['folder'] = '/Emissions//Matin/';
check('chronique : dossier nettoyé', ChronicleSettings::validate($bad, ['leahostid01'], [])['folder'], 'Emissions/Matin');
$bad = $chron;
$bad['schedule'] = ['days' => [1], 'times' => ['25:00']];
throws('chronique : heure invalide', static fn() => ChronicleSettings::validate($bad, ['leahostid01'], []), 'heure');
check('chronique : planifiée à 6h30', ChronicleSettings::dueSlot($valid, $at('2026-11-18 06:31'), []), '2026-11-18 06:30');
check('chronique : pas deux fois', ChronicleSettings::dueSlot($valid, $at('2026-11-18 06:35'), ['2026-11-18 06:30']), null);
check('chronique : pas avant l\'heure', ChronicleSettings::dueSlot($valid, $at('2026-11-18 06:29'), []), null);
check('chronique : rattrapage jusqu\'à 30 min', ChronicleSettings::dueSlot($valid, $at('2026-11-18 06:59'), []), '2026-11-18 06:30');
check('chronique : pas le week-end', ChronicleSettings::dueSlot($valid, $at('2026-11-21 06:31'), []), null);
$disabled = $valid;
$disabled['enabled'] = false;
check('chronique : désactivée', ChronicleSettings::dueSlot($disabled, $at('2026-11-18 06:31'), []), null);

$withChron = $input;
$withChron['chronicles'] = [$chron];
throws('chronique : animateur sans voix refusé', static fn() => Settings::fromInput($withChron, []), 'animateur');
$savedHosts = Settings::fromInput($input, []);
$chron['host_id'] = $savedHosts['hosts'][0]['id'];
$withChron = $savedHosts;
$withChron['chronicles'] = [$chron, $chron];
throws('chronique : noms en double', static fn() => Settings::fromInput($withChron, $savedHosts), 'Deux chroniques');
$withChron['chronicles'] = [$chron];
$withChronSaved = Settings::fromInput($withChron, $savedHosts);
check('chronique enregistrée', $withChronSaved['chronicles'][0]['name'], 'Infos régionales');
check('voix de la chronique = celle de l\'animateur', ChronicleSettings::voice($withChronSaved, $withChronSaved['chronicles'][0])['voice_id'], $lea['voice_id'] ?: '21m00Tcm4TlvDq8ikWAM');

$sources = [
    ['label' => 'Flux', 'url' => 'https://ex.org/rss', 'ok' => true, 'text' => '- Grève à la SNCB'],
    ['label' => 'Panne', 'url' => 'https://ex.org/x', 'ok' => false, 'text' => ''],
];
$cvars = PromptBuilder::variables(['name' => 'Léa', 'target_seconds' => 60], 'Radio Test', 'français', $at('2026-11-18 06:30')) + ['chronique' => 'Infos'];
$cm = PromptBuilder::chronicleMessages($withChronSaved, $withChronSaved['chronicles'][0], ['personality' => 'Dynamique.'], $cvars, $sources);
check('chronique : sources lues seulement', [str_contains($cm[1]['content'], 'Grève à la SNCB'), str_contains($cm[1]['content'], 'Panne')], [true, false]);
check('chronique : consigne « rien inventer » et réponse vide', str_contains($cm[0]['content'], 'N\'invente rien') && str_contains($cm[0]['content'], PromptBuilder::NO_CONTENT), true);
check('chronique : date du jour et nom', str_contains($cm[0]['content'], 'mercredi 18 novembre') && str_contains($cm[0]['content'], '« Infos »'), true);
check('chronique : style de la voix', str_contains($cm[0]['content'], 'Dynamique.'), true);

$feedNow = $at('2026-11-18 09:00');
$rss = '<?xml version="1.0" encoding="ISO-8859-1"?><rss version="2.0"><channel>'
    . '<item><title>Grève à la SNCB</title><description><![CDATA[<p>Trains perturb&eacute;s</p>]]></description><pubDate>Wed, 18 Nov 2026 07:30:00 +0100</pubDate></item>'
    . '<item><title>Ancien</title><description>vieux</description><pubDate>Mon, 16 Nov 2026 07:30:00 +0100</pubDate></item></channel></rss>';
$feed = Sources::extract(mb_convert_encoding($rss, 'ISO-8859-1', 'UTF-8'), 'application/rss+xml; charset=ISO-8859-1', 24, $feedNow);
check('source RSS : articles récents seulement, accents', [$feed['kind'], $feed['items'], $feed['text']], ['rss', 1, '- [18/11 07:30] Grève à la SNCB : Trains perturbés']);
check('source RSS : sans filtre de fraîcheur', Sources::extract(mb_convert_encoding($rss, 'ISO-8859-1', 'UTF-8'), 'text/xml', 0, $feedNow)['items'], 2);
$atom = '<feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Agenda</title><summary>Concert samedi</summary><updated>2026-11-18T08:00:00Z</updated></entry></feed>';
check('source Atom', Sources::extract($atom, 'application/atom+xml', 24, $feedNow)['text'], '- [18/11 09:00] Agenda : Concert samedi');
$page = '<html><head><title>Agenda</title><meta name="description" content="Les sorties"></head><body><nav>Menu</nav>'
    . '<main><h1>Ce week-end</h1><p>Concert à Namur.</p><script>x()</script><ul><li>Marché de Noël</li></ul></main><footer>© 2026</footer></body></html>';
check('source page web : texte principal sans menus ni scripts', Sources::extract($page, 'text/html; charset=utf-8', 24, $feedNow)['text'],
    "Agenda — Les sorties\n\nCe week-end\nConcert à Namur.\nMarché de Noël");
$weatherText = Weather::describe(['current' => ['temperature_2m' => 6.4, 'apparent_temperature' => 3.1, 'weather_code' => 61, 'wind_speed_10m' => 18],
    'daily' => ['time' => ['2026-11-18'], 'weather_code' => [61], 'temperature_2m_max' => [9.2], 'temperature_2m_min' => [4.1],
        'precipitation_probability_max' => [80], 'precipitation_sum' => [4.25], 'wind_speed_10m_max' => [30],
        'sunrise' => ['2026-11-18T08:05'], 'sunset' => ['2026-11-18T16:45']]], 'Namur');
check('météo : conditions en français', str_contains($weatherText, 'pluie faible, 6 °C (ressenti 3 °C)') && str_contains($weatherText, 'maximum 9 °C'), true);
[$cBed, $cOut] = ChronicleSettings::mixSettings($valid);
check('chronique : réglages de son propres, sans boucle', [$cBed['loop'], $cBed['duck_db'], $cOut['lufs']], [false, 15.0, -14.0]);
$custom = $chron;
$custom['sound'] = ['duck_db' => 20, 'intro' => 5, 'bitrate' => 999];
$customValid = ChronicleSettings::validate($custom, [$chron['host_id']], []);
check('chronique : son réglé par chronique', [$customValid['sound']['duck_db'], $customValid['sound']['intro'], $customValid['sound']['bitrate']], [20.0, 5.0, 192]);
$custom['bed_media'] = '../secret.mp3';
throws('chronique : fond musical invalide', static fn() => ChronicleSettings::validate($custom, [$chron['host_id']], []), 'fond musical');
$noLoop = Mixer::arguments('ffmpeg', 'v.mp3', 60.0, 'bed.mp3', 0.0, $cBed, $cOut, 'o.mp3', [], 20.0);
$noLoopGraph = $noLoop[array_search('-filter_complex', $noLoop, true) + 1];
check('fond court sans boucle : pas de répétition', in_array('-stream_loop', $noLoop, true), false);
check('fond court sans boucle : fondu à sa fin puis silence', str_contains($noLoopGraph, 'atrim=0:20,') && str_contains($noLoopGraph, 'afade=t=out:st=18:d=2') && str_contains($noLoopGraph, 'apad=whole_dur=66'), true);
$concat = Mixer::concatArguments('ffmpeg', ['a.mp3', 'b.mp3', 'c.mp3'], 'o.mp3', 192, ['title' => 'Infos']);
check('assemblage des jingles', str_contains(implode(' ', $concat), '[a0][a1][a2]concat=n=3:v=0:a=1[out]'), true);

$chronJobsRoot = sys_get_temp_dir() . '/av-chron-' . bin2hex(random_bytes(4));
mkdir($chronJobsRoot);
$chronJobs = new Jobs($chronJobsRoot);
$chronJobs->create(1, $day, ['kind' => 'chronicle', 'status' => 'done']);
$chronJobs->create(1, $day, ['kind' => 'between', 'status' => 'done']);
check('limite quotidienne : chroniques non comptées', $chronJobs->countToday(1, $day), 1);
exec('rm -rf ' . escapeshellarg($chronJobsRoot));

// --- Real mix, if ffmpeg is installed ------------------------------------------------------------
if (Shell::which('ffmpeg') !== null) {
    $dir = sys_get_temp_dir() . '/av-mix-' . bin2hex(random_bytes(4));
    mkdir($dir);
    Shell::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=5', '-ac', '1', $dir . '/voice.mp3']);
    Shell::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=110:duration=4', '-ac', '2', $dir . '/bed.mp3']);
    $mixer = new Mixer();
    $duration = $mixer->mix($dir . '/voice.mp3', $dir . '/bed.mp3', 4.0, Settings::defaults()['bed'], Settings::defaults()['output'], $dir . '/out.mp3', ['title' => 'Léa']);
    check('mixage réel : durée ≈ 3 + 5 + 3 s (fond bouclé)', abs($duration - 11.0) < 0.15, true);
    $level = static function (string $file, float $at, string $filter): float {
        $r = Shell::run(['ffmpeg', '-hide_banner', '-ss', (string)$at, '-t', '0.5', '-i', $file, '-af', $filter . ',volumedetect', '-f', 'null', '-']);
        preg_match('/mean_volume: (-?[\d.]+) dB/', $r['err'], $m);

        return (float)($m[1] ?? 0);
    };
    $before = $level($dir . '/out.mp3', 1.5, 'lowpass=f=200');
    $during = $level($dir . '/out.mp3', 5.0, 'lowpass=f=200');
    check('mixage réel : fond baissé d\'au moins 10 dB sous la voix', $before - $during >= 10, true);
    $short = $mixer->mix($dir . '/voice.mp3', $dir . '/bed.mp3', null, ['loop' => false] + Settings::defaults()['bed'], Settings::defaults()['output'], $dir . '/short.mp3');
    check('mixage réel sans boucle : durée complète malgré un fond de 4 s', abs($short - 11.0) < 0.15, true);
    check('mixage réel sans boucle : plus de fond après sa fin', $level($dir . '/short.mp3', 9.0, 'lowpass=f=200') < -60, true);
    $voice = $mixer->mix($dir . '/voice.mp3', null, null, Settings::defaults()['bed'], Settings::defaults()['output'], $dir . '/voice-only.mp3');
    $mixer->extract($dir . '/voice.mp3', $dir . '/sample.wav', 1.0, 3);
    check('extrait réel : 3 s en mono 24 kHz', [abs($mixer->duration($dir . '/sample.wav') - 3.0) < 0.1, str_contains(Shell::run(['ffprobe', '-v', 'error', '-show_entries', 'stream=sample_rate,channels', '-of', 'csv=p=0', $dir . '/sample.wav'])['out'], '24000,1')], [true, true]);
    check('mixage réel : voix seule', abs($voice - 5.8) < 0.15, true);
    exec('rm -rf ' . escapeshellarg($dir));
}

echo $failures === 0 ? "\nTous les tests sont passés.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
