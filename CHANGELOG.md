# Changements

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
