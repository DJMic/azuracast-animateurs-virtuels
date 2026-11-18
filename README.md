# AzuraCast Animateurs Virtuels

Plugin AzuraCast qui fait parler des **animateurs virtuels** entre les morceaux de vos stations.

Pour chaque intervention :

1. **DeepSeek** écrit le texte, à partir des consignes de la station, de la personnalité de l'animateur et d'un prompt. Il connaît le morceau qui vient de passer, celui qui suit, l'heure et le jour.
2. **ElevenLabs** lit ce texte avec la voix choisie (Voice ID) et produit un mp3.
3. **ffmpeg** mixe la voix sur un **fond musical** tiré d'une playlist. La musique démarre seule, baisse du nombre de dB choisi pendant la voix, remonte puis s'éteint en fondu. La longueur du fond s'adapte à celle de la voix.
4. Le clip est placé dans la **file d'attente de l'AutoDJ**, entre deux morceaux.

Chaque station reçoit une entrée **« Animateurs virtuels »** dans son menu, sous **Médias**. La page reprend le design d'AzuraCast, en thème clair comme en sombre.

## Fonctions

- **Plusieurs animateurs par station.** Chacun a :
  - un nom ;
  - une voix ElevenLabs (Voice ID) et ses propres réglages (stabilité, fidélité, style, vitesse) ;
  - une personnalité ;
  - ses prompts ;
  - ses tranches horaires ;
  - éventuellement sa propre playlist de fonds musicaux.
- **Tranches horaires** par jour de la semaine. Une tranche peut passer minuit (par exemple 22:00–02:00). Pendant une tranche, l'animateur parle :
  - **toutes les N chansons** (les jingles ne comptent pas) ;
  - et/ou à des **heures fixes** (par exemple 7:00, 7:30, 8:00). Il prend alors la parole à la fin du morceau en cours à cette heure-là.
- **Plusieurs prompts par animateur.** À chaque intervention, l'un d'eux est tiré au hasard. Un prompt peut être réservé aux enchaînements ou aux heures fixes.
- **Variables** utilisables dans les textes : `{animateur}`, `{station}`, `{heure}`, `{jour}`, `{date}`, `{moment}`, `{titre_precedent}`, `{artiste_precedent}`, `{titre_suivant}`, `{artiste_suivant}`, `{duree}`, `{mots}`, `{langue}`.
- **Écriture radio** (depuis la version 1.2.0) : DeepSeek reçoit des règles d'écriture pour l'oreille (style oral, idées reliées pour garder l'élan de la voix, exclamations et questions). Ces règles s'adaptent au modèle de voix : une ou deux indications de jeu comme `[warmly]` ou `[chuckles]` avec Eleven v4 et v3, une ou deux pauses `<break time="0.5s" />` avec les autres modèles. Option activée par défaut, dans « Consignes générales ».
- **Jusqu'à 5 comptes ElevenLabs par station** (depuis la version 1.3.0). Chaque animateur utilise les voix et les crédits de son compte.
- **Suivi des crédits ElevenLabs** de chaque compte : crédits utilisés et restants, date de remise à zéro, estimation de la date d'épuisement au rythme actuel, alerte sous un seuil. Un compte épuisé n'est plus sollicité : ses animateurs puisent dans la bibliothèque si elle est activée. Cette fonction nécessite la permission « User : lecture » sur la clé.
- **Eleven v4**, le modèle ElevenLabs le plus naturel et expressif, est proposé et recommandé.
- **Anti-répétition.** DeepSeek reçoit les dernières interventions de l'animateur pour varier ses formulations.
- **Bibliothèque de clips réutilisables** (depuis la version 1.1.0) : les voix obtenues avec des prompts « intemporels » sont gardées et rediffusées sur un nouveau fond musical, sans appel aux API. Voir « Bibliothèque » plus bas.
- **Fond musical réglable :**
  - baisse sous la voix (dB) et volume général ;
  - musique seule avant et après la voix ;
  - fondus d'entrée et de sortie, durée de la transition ;
  - départ au début du morceau ou à un endroit au hasard.
- **Normalisation du volume** (LUFS) et qualité du mp3.
- **Titre et artiste affichés** pendant l'intervention, réglables. On peut aussi garder le titre précédent, comme pour un jingle.
- **Limite quotidienne** d'interventions, réglable et désactivable, pour maîtriser les coûts.
- **Tester** un animateur : le texte et l'audio sont générés immédiatement, sans être diffusés. Un bouton **Diffuser maintenant** envoie un clip à l'antenne à la fin du morceau en cours.
- **Aperçu** du message exact envoyé à DeepSeek, depuis l'édition d'un prompt.
- **Historique** avec le texte, le fond utilisé, l'écoute de chaque clip et les erreurs éventuelles.
- **Nettoyage automatique** des clips au bout de X jours (7 par défaut).
- **Vérification** des clés API : le solde et les modèles DeepSeek, le nom de la voix et le quota ElevenLabs.

## Prérequis

- AzuraCast installé avec **Docker**, dans `/var/azuracast` par défaut, en version récente.
- L'**AutoDJ** activé sur les stations concernées : le plugin s'insère dans sa file d'attente.
- Une **file d'attente AutoDJ d'au moins 5 éléments** (Profil › Modifier le profil › onglet AutoDJ, « AutoDJ Queue Length »). Chaque intervention doit être générée avant que Liquidsoap ne la demande. Avec une file trop courte, surtout entre des jingles de quelques secondes, il n'y a pas le temps : le plugin reporte alors l'intervention et l'interface le signale.
- `git` sur le serveur, et un accès `root` ou `sudo`.
- Un compte **DeepSeek** avec du crédit, et une clé API (https://platform.deepseek.com, rubrique « API keys »).
- Un ou plusieurs comptes **ElevenLabs** (5 au maximum par station), avec une clé API par compte et le **Voice ID** de chaque voix. Chaque clé doit avoir au minimum les permissions **Text to Speech** et **Voices (lecture)**. La permission **User (lecture)** permet d'afficher les crédits restants. Attention : les comptes gratuits ne peuvent servir qu'à un usage non commercial, selon les conditions d'ElevenLabs.

Le serveur n'a pas besoin de Node.js : l'interface est compilée dans un conteneur Docker temporaire.

## Installation

```bash
sudo mkdir -p /var/azuracast/plugins
sudo git clone https://github.com/DJMic/azuracast-animateurs-virtuels.git \
    /var/azuracast/plugins/azuracast-animateurs-virtuels
cd /var/azuracast/plugins/azuracast-animateurs-virtuels
sudo bash install.sh
```

Le script s'occupe de tout :

1. il compile l'interface ;
2. il crée le dossier `data/` des clips générés, accessible en écriture par AzuraCast ;
3. il ajoute le plugin à `docker-compose.override.yml`, en gardant une copie de sauvegarde de l'ancien fichier. Les entrées des **autres plugins** (par exemple Show Downloader) ne sont pas modifiées ;
4. il active le « mode plugin » d'AzuraCast.

La première installation recrée le conteneur `web`, ce qui **coupe la diffusion environ une minute**. Le script demande donc confirmation avant de le faire. Avec `--yes`, il ne pose aucune question.

Installation en une seule commande :

```bash
curl -fsSL https://raw.githubusercontent.com/DJMic/azuracast-animateurs-virtuels/main/install.sh | sudo bash
```

## Mise à jour

```bash
cd /var/azuracast/plugins/azuracast-animateurs-virtuels
sudo bash bin/update.sh            # télécharge et installe la dernière version
sudo bash bin/update.sh --check    # indique seulement si une mise à jour existe
```

Une mise à jour ne coupe pas la diffusion : seul PHP est rechargé. Les réglages sont conservés, car ils sont enregistrés dans le dossier de chaque station. Les clips du dossier `data/` sont conservés aussi.

Le programme de mise à jour d'AzuraCast (`./docker.sh update`) ne met pas ce plugin à jour, mais le garde en place.

## Désinstallation

```bash
cd /var/azuracast/plugins/azuracast-animateurs-virtuels
sudo bash bin/uninstall.sh
```

Le script retire seulement les lignes de ce plugin de `docker-compose.override.yml`, puis recrée le conteneur `web`. Vous pouvez ensuite supprimer le dossier du plugin. Les réglages des stations (`animateurs-virtuels.json`) restent dans le dossier de configuration de chaque station.

## Mise en route

Ouvrez une station, puis **Animateurs virtuels**. Il faut l'autorisation « Gérer les médias » de la station.

1. **Services IA** : collez la clé DeepSeek, puis ajoutez un ou plusieurs comptes ElevenLabs (5 au maximum), chacun avec un nom et sa clé API. Cliquez sur **Vérifier** pour chaque clé. Réglez si besoin la limite quotidienne.
2. **Fond musical et son** : choisissez la playlist des fonds musicaux. Créez-la d'abord dans AzuraCast :
   - type **« Avancée »**, pour que l'AutoDJ ne la diffuse pas seule ;
   - **activée** ;
   - remplie de morceaux instrumentaux.

   Réglez ensuite la baisse sous la voix : 15 dB par défaut.
3. **Animateurs** › **Ajouter un animateur** :
   - nom, Voice ID (bouton **Vérifier**), durée visée et personnalité ;
   - prompts : deux exemples sont proposés ;
   - tranches horaires.
4. Cliquez sur **Tester** à côté de l'animateur. Écoutez le résultat et ajustez les prompts ou les réglages du son.
5. Cochez **Activer les animateurs virtuels sur cette station**, puis **Enregistrer**.

Les interventions apparaissent dans la **file d'attente** d'AzuraCast (Diffusion › File d'attente). Leur titre est celui de l'animateur. Elles apparaissent aussi dans l'onglet **Historique** du plugin.

## Fonctionnement détaillé

- **Quand l'animateur parle-t-il ?** Quand AzuraCast ajoute un morceau à sa file d'attente, le plugin regarde quel animateur est programmé à l'heure prévue de diffusion. Si une intervention est due, il la place juste avant ce morceau.
  - « Toutes les N chansons » : l'animateur parle juste après le Nᵉ morceau, jingles exclus. Avec une programmation musique / jingle / musique…, il parle donc entre le morceau et le jingle.
  - `{titre_precedent}` et `{titre_suivant}` désignent toujours des morceaux, jamais des jingles. Si le morceau suivant n'est pas encore choisi au moment de la génération, il n'est pas annoncé.
  - Heure fixe : l'animateur parle avant le premier morceau qui démarre à cette heure ou après. Si la file n'atteint cette heure qu'avec plus de 15 minutes de retard, l'intervention est abandonnée.
  - Si deux tranches se chevauchent, c'est l'animateur placé le plus haut dans la liste qui parle. L'interface le signale.
- **Préparation à l'avance.** AzuraCast prépare sa file plusieurs éléments à l'avance (réglage « AutoDJ Queue Length », 5 ou plus recommandé). Le clip est généré tout de suite, en arrière-plan, pendant ces quelques minutes. Si le lancement immédiat échoue, la tâche de synchronisation d'AzuraCast le génère dans la minute.
- **En cas de problème** (service indisponible, crédit épuisé…), l'intervention est retirée de la file : la musique continue normalement. Si un clip n'est pas prêt au moment de la diffusion, une demi-seconde de silence est diffusée à la place. L'erreur est visible dans l'historique.
- **Fondu enchaîné.** Le fondu enchaîné d'AzuraCast s'applique aussi aux interventions. Avec la musique seule avant et après la voix (3 s par défaut), la voix n'est jamais coupée.

## Chroniques (depuis la version 1.4.0)

La page est organisée en quatre parties :
- **Interventions** : les animateurs entre les morceaux ;
- **Chroniques** ;
- **Services IA** : clés et crédits, communs aux deux ;
- **Historique** : interventions et chroniques, avec un filtre.

Une chronique (infos, agenda, météo, horoscope…) est produite en cinq étapes :

1. **Lecture des sources.** Le plugin lit les adresses indiquées (10 au maximum) :
   - **flux RSS/Atom** : c'est le plus fiable. Seuls les articles des dernières X heures sont gardés, 24 par défaut ;
   - **pages web** : le texte principal est extrait, sans les menus ni les scripts. Certains sites bloquent les robots ou se construisent en JavaScript : le bouton « Tester les sources » montre ce qui est réellement lu ;
   - **météo** : prévisions d'Open-Meteo pour une ville. Ce service est gratuit et sans clé, mais réservé à un usage non commercial.
2. **Écriture.** DeepSeek écrit le texte uniquement à partir des sources, avec la date du jour et la règle de ne rien inventer. Si aucune source n'est lisible, ou si les sources ne contiennent rien d'utile, rien n'est publié et la version précédente reste en place.
3. **Voix et mixage.** ElevenLabs lit le texte avec la voix d'un animateur ou une voix propre à la chronique. La voix est mixée sur un fond musical avec les réglages de « Fond musical et son », puis un jingle d'ouverture et/ou de fermeture de la médiathèque peut être ajouté.
4. **Enregistrement dans les médias.** Le fichier est enregistré dans `Chroniques/<nom>/` (dossier modifiable, créé s'il n'existe pas), par exemple `Meteo_2026-11-18_0630.mp3`, avec un titre et un artiste. Le plugin utilise le même mécanisme qu'un envoi depuis AzuraCast : il fonctionne avec un stockage local comme avec un stockage distant S3.
5. **Playlist et nettoyage.** Le fichier est ajouté à la playlist choisie et la version précédente en est retirée : la playlist ne contient toujours que la plus récente. Programmez cette playlist avec le planificateur d'AzuraCast. Les anciennes versions sont supprimées des médias après X jours (0 = jamais). La plus récente n'est jamais supprimée, et le plugin ne touche qu'aux fichiers qu'il a créés.

La génération se fait aux jours et heures choisis pour chaque chronique, ou avec le bouton « Générer maintenant ». Prévoyez-la quelques minutes avant l'heure de diffusion. Les chroniques ne comptent pas dans la limite quotidienne des interventions.

**Attention aux crédits ElevenLabs** : une minute de chronique fait environ 900 caractères. Une chronique quotidienne d'une minute consomme donc environ 27 000 crédits par mois.

## Bibliothèque : moins d'appels aux API avec le temps

Chaque intervention coûte un appel à DeepSeek et, surtout, des caractères ElevenLabs. La bibliothèque permet de réutiliser des voix déjà enregistrées.

1. **Prompts intemporels.** Dans l'édition d'un animateur, onglet « Prompts », cochez « Intemporel » sur les prompts dont le résultat peut être rediffusé à tout moment. Un nouvel animateur en a un d'exemple : « Ambiance (réutilisable) ». Un tel prompt ne peut pas utiliser `{heure}`, `{jour}`, `{date}`, `{moment}` ni les titres et artistes. DeepSeek reçoit en plus la consigne de ne citer ni moment, ni date, ni saison, ni météo, ni morceau.
2. **Vérification automatique.** Avant d'entrer dans la bibliothèque, le texte est contrôlé. Il est écarté s'il contient une heure, un jour, un mois, « bonjour », « bonsoir », « ce matin », « demain », « le morceau suivant »… L'historique indique pourquoi une voix n'a pas été gardée.
3. **Réutilisation progressive.** Dans l'onglet « Bibliothèque » de l'animateur, activez « Réutiliser les clips de la bibliothèque ». En dessous du minimum (20 clips par défaut), toutes les interventions sont nouvelles. Ensuite, la part de clips réutilisés augmente avec la taille de la bibliothèque, jusqu'au maximum choisi (60 % par défaut, atteint à 3 fois le minimum). Seules les interventions « entre deux morceaux » sont concernées : les heures fixes restent toujours nouvelles.
4. **Anti-lassitude.**
   - Le clip le moins récemment diffusé passe en premier.
   - Un clip ne repasse pas avant X jours (3 par défaut).
   - Un nombre maximal de diffusions par clip est possible : une fois atteint, le clip est retiré.
   - Une taille maximale de bibliothèque peut être fixée : au-delà, le clip le plus diffusé laisse sa place.
5. **Secours.** Si DeepSeek ou ElevenLabs est en panne, ou si la limite quotidienne est atteinte, un clip de la bibliothèque est diffusé au lieu de rien. Les clips réutilisés ne comptent pas dans la limite quotidienne.
6. **Contrôle.** L'onglet « Bibliothèque » de la page liste les clips de chaque animateur. On peut les écouter et supprimer ceux qui ne conviennent pas. Si la voix d'un animateur change (Voice ID, modèle ou réglages), ses anciens clips ne sont plus réutilisés. Un bouton permet de les supprimer.

La bibliothèque n'est pas concernée par le nettoyage automatique des clips au bout de X jours. Elle se remplit même quand la réutilisation est désactivée : vous pouvez la constituer avant de l'activer.

## Où sont les données ?

| Données | Emplacement |
| --- | --- |
| Réglages et clés API | `/var/azuracast/stations/<station>/config/animateurs-virtuels.json` (droits 0600) |
| Clips, historique, état | `/var/azuracast/plugins/azuracast-animateurs-virtuels/data/<id station>/` |
| Bibliothèque (voix réutilisables) | `…/data/<id station>/library/<id animateur>/` |

Dans le conteneur, le dossier `data/` est monté sur `/var/azuracast/plugin-data/animateurs-virtuels`.

## Coûts

- **DeepSeek** facture au nombre de jetons. Une intervention coûte une fraction de centime.
- **ElevenLabs** décompte les caractères de votre abonnement. Une intervention de 20 secondes fait environ 300 caractères. Avec une intervention toutes les 4 chansons de 6 h à 9 h, comptez environ 15 interventions par jour et par animateur.

Chaque intervention de l'historique indique le nombre de caractères ElevenLabs utilisés. Les clips réutilisés depuis la bibliothèque ne coûtent rien.

## Commandes utiles

```bash
cd /var/azuracast
# Dernières interventions d'une station (ID ou nom court)
docker compose exec web azuracast_cli animateurs-virtuels:generate ma_station
# Relancer la génération d'une intervention en attente
docker compose exec web azuracast_cli animateurs-virtuels:generate ma_station 20261116-070000-a1b2c3
# Journal
docker compose logs -f web | grep -i "animateurs virtuels"
```

## Dépannage

- **« Le dossier de données n'est pas accessible en écriture »** : relancez `sudo bash bin/setup.sh`. Si AzuraCast tourne avec un autre utilisateur que 1000, vérifiez `AZURACAST_PUID` et `AZURACAST_PGID` dans `/var/azuracast/.env`.
- **Aucune intervention à l'antenne** : vérifiez trois choses :
  - l'interrupteur d'activation est coché ;
  - un animateur actif a une tranche en cours (l'en-tête de la page l'indique) ;
  - l'AutoDJ de la station est activé.
- **Erreur HTTP 401/402 dans l'historique** : la clé API est refusée (401) ou le crédit est épuisé (402).
- **Voix trop forte ou trop faible par rapport à la musique** : ajustez le volume cible (LUFS) dans « Fond musical et son ».

## Évolutions envisagées

- Météo et actualités, via une source et une clé API supplémentaires.
- Export et import des animateurs d'une station à l'autre.

## Licence

MIT
