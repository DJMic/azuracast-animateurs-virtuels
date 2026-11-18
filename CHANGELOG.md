# Changements

## 1.5.0 – 2026-11-18

- **Mistral Voxtral, un second service de voix.** Chaque animateur, et chaque chronique à voix propre, choisit maintenant entre ElevenLabs et Voxtral.
  - **Clé Mistral** par station, dans « Services IA », avec un bouton « Vérifier ». Le plugin peut être activé avec Voxtral seul, sans compte ElevenLabs.
  - **Voix Mistral** prêtes à l'emploi (les quatre voix françaises sont proposées) ou voix enregistrées dans Mistral AI Studio (bouton « Charger les voix »).
  - **Voix clonée depuis un fichier des médias** : un extrait (début et durée réglables) est envoyé avec chaque demande, rien n'est stocké chez Mistral.
  - **Expressivité** réglable et bouton **« Écouter un essai »** pour choisir une voix avant d'enregistrer.
  - **Écriture radio** adaptée : balises `[emotion=…]` et `[pace=…]` comprises par Voxtral ; les indications de style ElevenLabs sont converties ou retirées.
  - **Textes longs** (chroniques) lus en plusieurs parties, assemblées automatiquement.
  - **Suivi** : caractères envoyés à Voxtral ce mois-ci et le mois précédent, avec une estimation haute du coût.
- La bibliothèque distingue les voix des deux services : un animateur qui change de service ne rediffuse pas les clips de son ancienne voix.

## 1.4.1 – 2026-11-18

- **Chroniques : fond musical et son propres à chaque chronique.**
  - Le fond musical se choisit parmi les fichiers des médias (recherche par nom) au lieu d'une playlist.
  - Chaque chronique a ses propres réglages : baisse sous la voix, volume du fond, musique seule avant et après, fondus, transition, départ au hasard, normalisation et qualité mp3. Ils sont indépendants de ceux des interventions.
  - Le fond n'est jamais répété en boucle. S'il est plus court que la chronique, il s'arrête en fondu et la voix continue seule, et la fiche de la chronique le signale.

## 1.4.0 – 2026-11-18

- **Chroniques** : infos, agenda, météo, horoscope… autant que vous voulez par station.
  - **Sources** : adresses web, flux RSS/Atom (articles des dernières X heures) ou météo Open-Meteo par ville, avec un bouton « Tester les sources ».
  - **Écriture** : DeepSeek écrit uniquement à partir des sources, sans rien inventer. Si les sources sont illisibles ou vides, rien n'est publié.
  - **Voix** : celle d'un animateur ou une voix propre (compte ElevenLabs, Voice ID et réglages).
  - **Mixage** : fond musical (celui de la station ou une playlist au choix), jingles d'ouverture et de fermeture pris dans la médiathèque.
  - **Enregistrement** dans les médias de la station, local ou S3, dans un dossier créé automatiquement (`Chroniques/<nom>/` par défaut), avec un titre et un artiste.
  - **Playlist** : la nouvelle version est ajoutée à la playlist choisie et la précédente en est retirée.
  - **Nettoyage** : suppression des anciennes versions après X jours, jamais de la plus récente.
  - **Génération** planifiée (jours et heures) ou manuelle (« Générer maintenant »), suivie dans l'historique (sources lues, texte, écoute, fichier créé).
- **Nouvelle organisation de la page** : Interventions, Chroniques, Services IA (commun), Historique (avec un filtre).
- Les chroniques ne comptent pas dans la limite quotidienne des interventions.

## 1.3.2 – 2026-11-18

- **« Masquer l'intervention des métadonnées »** (anciennement « Ne pas changer le titre affiché ») fonctionne vraiment. Le titre et l'artiste ne sont plus transmis à Liquidsoap, en plus du mode jingle :
  - le serveur de stream garde les métadonnées du morceau précédent ;
  - AzuraCast ne crée pas de ligne d'historique, donc l'API Now Playing et les widgets continuent d'afficher le morceau précédent.

  L'intervention n'apparaît pas, même grisée, dans l'historique d'AzuraCast : seuls les fichiers de la médiathèque peuvent l'être. Elle reste dans l'historique du plugin. Les clips ne contiennent plus d'étiquettes ID3 titre/artiste : ces informations passent uniquement par Liquidsoap.
- **Clip coupé après l'intro : vraie correction.** L'indication utilisée en 1.3.1 pour désactiver l'AutoCue était filtrée par AzuraCast, qui n'accepte qu'une liste précise d'indications. Le plugin fournit maintenant lui-même le calage du clip avec les indications autorisées (`azuracast_autocue`, début, fin, fondus) : Liquidsoap n'analyse plus le clip. Le morceau suivant démarre au moment où le fond musical du clip commence son fondu de sortie.
- « Diffuser maintenant » utilise les mêmes indications.

## 1.3.1 – 2026-11-17

- **Interventions parfois remplacées par un silence.** Deux causes, corrigées :
  - la génération immédiate en arrière-plan ne démarrait jamais : le plugin cherchait la console AzuraCast à l'ancien emplacement (`bin/console` au lieu de `backend/bin/console`). Seule la tâche de synchronisation, une fois par minute, générait les clips, souvent trop tard ;
  - avec une file d'attente AutoDJ courte et des jingles de quelques secondes, l'intervention était placée trop peu de temps avant sa diffusion. Le plugin vérifie maintenant qu'il reste au moins 45 secondes pour la générer, sinon il la reporte au moment suivant (une heure fixe reste valable 15 minutes). L'interface signale une file d'attente AutoDJ de moins de 4 éléments.
- **Clip coupé après le début du fond musical.** L'AutoCue de Liquidsoap analysait le clip et prenait la partie voix, plus calme que l'intro musicale, pour sa fin : le morceau suivant démarrait trop tôt. L'AutoCue est désactivé pour les interventions, qui ont déjà leurs propres fondus et leur niveau.
- **Place des interventions « entre deux morceaux ».** Elles arrivent juste après le Nᵉ morceau, donc avant le jingle avec une programmation musique / jingle / musique, comme les heures fixes.
- **`{titre_precedent}`, `{titre_suivant}` et les artistes ignorent les jingles.** Quand l'intervention précède un jingle, le morceau qui suit ce jingle est cherché dans la file d'attente au moment de la génération.
- Liquidsoap peut attendre un clip encore en cours de génération jusqu'à 8 secondes au lieu de 6.

## 1.3.0 – 2026-11-17

- **Jusqu'à 5 comptes ElevenLabs par station**, chacun avec un nom et sa clé API.
  - Chaque animateur choisit son compte dans sa fiche, à côté du Voice ID. La voix doit appartenir à ce compte, et la vérification se fait avec sa clé.
  - À la mise à jour, la clé existante devient automatiquement « Compte 1 », et tous les animateurs y restent rattachés.
  - Un compte utilisé par un animateur ne peut pas être retiré.
- **Suivi des crédits ElevenLabs.** Le plugin lit les crédits de chaque compte par l'API ElevenLabs, ce qui ne consomme aucun crédit, toutes les 10 minutes et après chaque intervention générée.
  - Pour chaque compte, dans « Services IA » : barre de progression, crédits utilisés et restants, date de remise à zéro, offre.
  - Estimation de la date d'épuisement au rythme actuel, quand elle tombe avant la remise à zéro.
  - Résumé dans l'en-tête de la page, avec un seuil d'alerte réglable (500 crédits par défaut).
  - Un compte épuisé (ou dont les crédits restants ne suffisent pas pour le texte) n'est plus appelé : l'intervention passe par la bibliothèque si le secours est activé, sinon elle est annulée proprement.
- L'historique indique le compte ElevenLabs utilisé pour chaque intervention.

## 1.2.0 – 2026-11-16

- **Eleven v4** ajouté à la liste des modèles de voix et recommandé. C'est le modèle par défaut des nouvelles stations. Sur une station existante, choisissez-le dans « Services IA ».
- **Écriture radio**, option activée par défaut dans « Consignes générales ». DeepSeek reçoit des règles d'écriture pour l'oreille :
  - syntaxe orale ;
  - idées reliées par des virgules, des tirets ou des points de suspension plutôt qu'une série de phrases fermées par un point ;
  - exclamations et questions.

  Ces règles s'adaptent au modèle de voix :
  - avec Eleven v4 et v3 : une ou deux indications de jeu en anglais entre crochets (`[warmly]`, `[chuckles]`…) ;
  - avec les autres modèles : une ou deux pauses `<break time="…" />`.
- Nettoyage du texte adapté au modèle :
  - avec v3 et v4, 3 indications de jeu au maximum, et aucune balise `<break>`, que ces modèles ne comprennent pas ;
  - avec les autres modèles, 2 pauses au maximum, de 0,2 à 3 secondes, et plus aucune indication entre crochets ;
  - les autres balises sont retirées dans tous les cas.
- La typographie française (espace avant « ! » et « ? ») est conservée dans les textes.

## 1.1.0 – 2026-11-16

- **Bibliothèque de clips réutilisables**, pour réduire les appels à DeepSeek et à ElevenLabs avec le temps :
  - case « Intemporel » sur les prompts : un tel prompt ne peut pas utiliser de variable liée au moment ou aux morceaux, et DeepSeek reçoit une consigne en ce sens ;
  - les voix obtenues avec ces prompts sont gardées par animateur, après une vérification automatique du texte (heure, jour, « bonjour », « ce soir », « morceau suivant »… sont refusés) ;
  - réutilisation progressive : à partir d'une taille minimale, une part croissante des interventions « entre deux morceaux » reprend une voix de la bibliothèque sur un nouveau fond musical, sans appel aux API ;
  - le clip le moins récemment diffusé passe en premier ; on règle le délai avant rediffusion, le nombre maximal de diffusions et la taille maximale ;
  - secours en cas de panne ou de limite quotidienne atteinte ;
  - nouvel onglet « Bibliothèque » : écoute et suppression des clips, suppression des clips enregistrés avec une ancienne voix.
- Les clips réutilisés ne comptent pas dans la limite quotidienne. L'en-tête de la page indique aussi combien de clips ont été réutilisés aujourd'hui.
- Historique : badges « Réutilisée » et « + bibliothèque », avec une note explicative.
- Un nouvel animateur reçoit un troisième prompt d'exemple, intemporel : « Ambiance (réutilisable) ».

## 1.0.1 – 2026-11-16

- Correction : avec d'autres plugins présents dans le menu sous « Médias » (Show Downloader, Sweepers…), les pages des plugins restaient vides dans Chrome. Les entrées de menu s'échangeaient leur place sans arrêt, ce qui bloquait la page. Une entrée déjà placée parmi celles des plugins n'est plus jamais déplacée, et son état « actif » est mis à jour sans la recréer.

## 1.0.0 – 2026-11-14

Première version.

- Plusieurs animateurs virtuels par station, chacun avec :
  - une voix ElevenLabs et ses propres réglages ;
  - une personnalité ;
  - ses prompts ;
  - ses tranches horaires ;
  - éventuellement sa propre playlist de fonds musicaux.
- Interventions toutes les N chansons et/ou à heures fixes, placées dans la file d'attente de l'AutoDJ.
- Textes écrits par DeepSeek. Une clé API par station, modèle et créativité réglables.
- Voix ElevenLabs. Une clé API par station, modèle et code langue réglables.
- Mixage ffmpeg sur un fond musical tiré d'une playlist. On règle :
  - la baisse sous la voix (dB) ;
  - la musique seule avant et après la voix ;
  - les fondus ;
  - la normalisation LUFS.
- Limite quotidienne réglable et désactivable.
- Test, aperçu du prompt, diffusion immédiate et historique avec écoute.
- Scripts d'installation, de mise à jour et de désinstallation. Ils restent compatibles avec les autres plugins.
