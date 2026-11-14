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
- **Anti-répétition.** DeepSeek reçoit les dernières interventions de l'animateur pour varier ses formulations.
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
- `git` sur le serveur, et un accès `root` ou `sudo`.
- Un compte **DeepSeek** avec du crédit, et une clé API (https://platform.deepseek.com, rubrique « API keys »).
- Un compte **ElevenLabs**, une clé API et le **Voice ID** de chaque voix. La clé doit avoir au minimum les permissions **Text to Speech** et **Voices (lecture)**. La permission **User (lecture)** est facultative : elle permet d'afficher le quota restant.

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

1. **Services IA** : collez la clé DeepSeek et la clé ElevenLabs, puis cliquez sur **Vérifier**. Réglez si besoin la limite quotidienne.
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
  - « Toutes les N chansons » : l'animateur parle après N morceaux, jingles exclus.
  - Heure fixe : l'animateur parle avant le premier morceau qui démarre à cette heure ou après. Si la file n'atteint cette heure qu'avec plus de 15 minutes de retard, l'intervention est abandonnée.
  - Si deux tranches se chevauchent, c'est l'animateur placé le plus haut dans la liste qui parle. L'interface le signale.
- **Préparation à l'avance.** AzuraCast prépare sa file plusieurs morceaux à l'avance (3 par défaut). Le clip est généré tout de suite, en arrière-plan, pendant ces quelques minutes. Si le lancement immédiat échoue, la tâche de synchronisation d'AzuraCast le génère dans la minute.
- **En cas de problème** (service indisponible, crédit épuisé…), l'intervention est retirée de la file : la musique continue normalement. Si un clip n'est pas prêt au moment de la diffusion, une demi-seconde de silence est diffusée à la place. L'erreur est visible dans l'historique.
- **Fondu enchaîné.** Le fondu enchaîné d'AzuraCast s'applique aussi aux interventions. Avec la musique seule avant et après la voix (3 s par défaut), la voix n'est jamais coupée.

## Où sont les données ?

| Données | Emplacement |
| --- | --- |
| Réglages et clés API | `/var/azuracast/stations/<station>/config/animateurs-virtuels.json` (droits 0600) |
| Clips, historique, état | `/var/azuracast/plugins/azuracast-animateurs-virtuels/data/<id station>/` |

Dans le conteneur, le dossier `data/` est monté sur `/var/azuracast/plugin-data/animateurs-virtuels`.

## Coûts

- **DeepSeek** facture au nombre de jetons. Une intervention coûte une fraction de centime.
- **ElevenLabs** décompte les caractères de votre abonnement. Une intervention de 20 secondes fait environ 300 caractères. Avec une intervention toutes les 4 chansons de 6 h à 9 h, comptez environ 15 interventions par jour et par animateur.

Chaque intervention de l'historique indique le nombre de caractères ElevenLabs utilisés.

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
