<?php

declare(strict_types=1);

// Standalone tests for the logic that does not need AzuraCast: php tests/run.php

foreach (['Plugin', 'Settings', 'Planner', 'PromptBuilder', 'Shell', 'Mixer', 'Store', 'Jobs', 'Http', 'ElevenLabs'] as $class) {
    require __DIR__ . '/../src/Service/' . $class . '.php';
}

use Plugin\AzuracastAnimateursVirtuels\Service\ElevenLabs;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Mixer;
use Plugin\AzuracastAnimateursVirtuels\Service\Planner;
use Plugin\AzuracastAnimateursVirtuels\Service\PromptBuilder;
use Plugin\AzuracastAnimateursVirtuels\Service\Settings;
use Plugin\AzuracastAnimateursVirtuels\Service\Shell;

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
$input['elevenlabs']['api_key'] = 'sk_eleven_9876';
$input['enabled'] = true;

$saved = Settings::fromInput($input, []);
check('ids attribués', strlen($saved['hosts'][0]['id']) === 12 && strlen($saved['hosts'][0]['slots'][0]['id']) === 12, true);
check('clé conservée', $saved['deepseek']['api_key'], 'sk-deepseek-1234');
$display = Settings::forDisplay($saved);
check('clé masquée', $display['deepseek']['api_key'], Settings::MASK);
check('indice de clé', $display['elevenlabs']['api_key_hint'], '…9876');
$again = Settings::fromInput($display, $saved);
check('masque = clé inchangée', $again['elevenlabs']['api_key'], 'sk_eleven_9876');
check('ids stables', $again['hosts'][0]['id'], $saved['hosts'][0]['id']);
$cleared = $display;
$cleared['enabled'] = false;
$cleared['deepseek']['api_key_clear'] = true;
check('suppression de clé', Settings::fromInput($cleared, $saved)['deepseek']['api_key'], '');
$noKey = $input;
$noKey['elevenlabs']['api_key'] = '';
throws('activation sans clé refusée', static fn() => Settings::fromInput($noKey, []), 'clé API');
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
check('heure fixe : déclenchée', $d2, ['kind' => 'fixed', 'time' => '07:00']);
check('heure fixe : une seule fois', $d3, null);
check('heure fixe : compteur remis à 1', $state['count'], 2);
[$late, $state] = Planner::decide(['slot' => null], Planner::onAir($settings, $at('2026-11-16 07:20')), $at('2026-11-16 07:20'), true);
check('heure fixe : trop tard (> 15 min)', $late, null);
[$nextDay] = Planner::decide($state, Planner::onAir($settings, $at('2026-11-17 07:02')), $at('2026-11-17 07:02'), true);
check('heure fixe : le lendemain à nouveau', $nextDay['kind'] ?? null, 'fixed');

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
check('nettoyage v3 garde les balises', PromptBuilder::clean('[joyeux] Salut', true), '[joyeux] Salut');
check('coupure à la phrase', PromptBuilder::clean(str_repeat('Phrase courte. ', 30), false, 100), trim(str_repeat('Phrase courte. ', 6)));

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
    $voice = $mixer->mix($dir . '/voice.mp3', null, null, Settings::defaults()['bed'], Settings::defaults()['output'], $dir . '/voice-only.mp3');
    check('mixage réel : voix seule', abs($voice - 5.8) < 0.15, true);
    exec('rm -rf ' . escapeshellarg($dir));
}

echo $failures === 0 ? "\nTous les tests sont passés.\n" : "\n$failures test(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
