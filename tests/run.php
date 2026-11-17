<?php

declare(strict_types=1);

// Standalone tests for the logic that does not need AzuraCast: php tests/run.php

foreach (['Plugin', 'Settings', 'Planner', 'PromptBuilder', 'Shell', 'Mixer', 'Store', 'Jobs', 'Http', 'ElevenLabs', 'Library', 'Credits'] as $class) {
    require __DIR__ . '/../src/Service/' . $class . '.php';
}

use Plugin\AzuracastAnimateursVirtuels\Service\Credits;
use Plugin\AzuracastAnimateursVirtuels\Service\ElevenLabs;
use Plugin\AzuracastAnimateursVirtuels\Service\Jobs;
use Plugin\AzuracastAnimateursVirtuels\Service\Library;
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
exec('rm -rf ' . escapeshellarg($statsRoot));

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
