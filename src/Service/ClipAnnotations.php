<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

/**
 * Annotations sent to Liquidsoap with a clip (pure function, tested).
 *
 * Only the keys AzuraCast lets through are used (AnnotateNextSong::ALLOWED_ANNOTATIONS): any
 * other key is silently dropped by AzuraCast.
 *
 * - AutoCue: the clip's cue points are given ("azuracast_autocue"), so Liquidsoap does not analyse
 *   the clip. Its analysis took the quieter voice part for the end of the track and started the
 *   next one right after the intro. The next item starts when the music bed fades out.
 * - "Hide metadata": no title and no artist, plus jingle mode. Liquidsoap keeps the previous
 *   song's metadata on the stream, and AzuraCast creates no history entry, so the Now Playing API
 *   keeps showing the previous song (as for a jingle).
 */
final class ClipAnnotations
{
    public static function build(
        string $title,
        string $artist,
        float $duration,
        bool $hideMetadata,
        float $bedFadeOut = 0.0
    ): array {
        $duration = max(0.1, round($duration, 3));
        // The next item starts when the music fades out (or just before the end of a voice-only clip).
        $startNext = $bedFadeOut > 0 ? $duration - $bedFadeOut : $duration - 0.3;
        $startNext = round(max(0.0, min($duration, $startNext)), 3);

        $annotations = [
            'title' => $hideMetadata ? '' : $title,
            'artist' => $hideMetadata ? '' : $artist,
            'duration' => $duration,
            'azuracast_autocue' => 'true',
            'autocue_cue_in' => 0.0,
            'autocue_cue_out' => $duration,
            'autocue_fade_in' => 0.0,
            'autocue_fade_out' => 0.1,
            'autocue_start_next' => $startNext,
        ];
        if ($hideMetadata) {
            $annotations['jingle_mode'] = 'true';
        }

        return $annotations;
    }
}
