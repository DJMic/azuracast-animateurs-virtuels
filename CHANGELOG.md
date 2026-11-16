# Changements

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
