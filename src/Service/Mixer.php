<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use RuntimeException;

/**
 * Mixes the voice over the music bed with ffmpeg, in a single mp3:
 *
 *   music alone (intro) → music lowered by N dB under the voice → music back up (outro) → fade out
 *
 * The bed is cut (or looped if too short) to intro + voice + outro.
 */
final class Mixer
{
    /** Silence added before and after the voice when there is no music bed. */
    public const float VOICE_ONLY_LEAD = 0.3;
    public const float VOICE_ONLY_TAIL = 0.5;

    public function duration(string $path): float
    {
        $ffprobe = Shell::which('ffprobe');
        if ($ffprobe !== null) {
            $result = Shell::run([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path], 30);
            if ($result['code'] === 0 && is_numeric(trim($result['out']))) {
                return (float)trim($result['out']);
            }
        }
        $result = Shell::run([$this->ffmpeg(), '-hide_banner', '-i', $path, '-f', 'null', '-'], 60);
        if (preg_match_all('/time=(\d+):(\d+):(\d+(?:\.\d+)?)/', $result['err'], $m) && $m[0] !== []) {
            $i = count($m[0]) - 1;

            return (int)$m[1][$i] * 3600 + (int)$m[2][$i] * 60 + (float)$m[3][$i];
        }
        throw new RuntimeException('Durée du fichier audio illisible : ' . basename($path));
    }

    /**
     * @return float duration of the result, in seconds
     */
    public function mix(
        string $voicePath,
        ?string $bedPath,
        ?float $bedDuration,
        array $bed,
        array $output,
        string $outPath,
        array $metadata = []
    ): float {
        $voiceDuration = $this->duration($voicePath);
        $offset = 0.0;
        if ($bedPath !== null && !empty($bed['random_start']) && $bedDuration !== null) {
            $total = $bed['intro'] + $voiceDuration + $bed['outro'];
            if ($bedDuration > $total + 5) {
                $offset = round(mt_rand(0, (int)(($bedDuration - $total - 2) * 10)) / 10, 1);
            }
        }

        $args = self::arguments($this->ffmpeg(), $voicePath, $voiceDuration, $bedPath, $offset, $bed, $output, $outPath, $metadata);
        $result = Shell::run($args, 120);
        if ($result['code'] !== 0 || !is_file($outPath) || filesize($outPath) < 1000) {
            @unlink($outPath);
            throw new RuntimeException('Mixage ffmpeg impossible : ' . trim(mb_substr($result['err'], -600)));
        }

        return $this->duration($outPath);
    }

    /**
     * Joins files end to end (opening jingle + chronicle + closing jingle), in one mp3.
     *
     * @param string[] $paths
     *
     * @return float duration of the result, in seconds
     */
    public function concat(array $paths, string $outPath, int $bitrate, array $metadata = []): float
    {
        $args = self::concatArguments($this->ffmpeg(), $paths, $outPath, $bitrate, $metadata);
        $result = Shell::run($args, 300);
        if ($result['code'] !== 0 || !is_file($outPath) || filesize($outPath) < 1000) {
            @unlink($outPath);
            throw new RuntimeException('Assemblage ffmpeg impossible : ' . trim(mb_substr($result['err'], -600)));
        }

        return $this->duration($outPath);
    }

    /**
     * @param string[] $paths
     *
     * @return string[]
     */
    public static function concatArguments(string $ffmpeg, array $paths, string $outPath, int $bitrate, array $metadata = []): array
    {
        $args = [$ffmpeg, '-hide_banner', '-nostdin', '-y'];
        $graph = '';
        $labels = '';
        foreach (array_values($paths) as $i => $path) {
            array_push($args, '-i', $path);
            $graph .= '[' . $i . ':a]aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo[a' . $i . '];';
            $labels .= '[a' . $i . ']';
        }
        $graph .= $labels . 'concat=n=' . count($paths) . ':v=0:a=1[out]';
        array_push($args, '-filter_complex', $graph, '-map', '[out]', '-map_metadata', '-1');
        foreach ($metadata as $key => $value) {
            if ($value !== '') {
                array_push($args, '-metadata', $key . '=' . $value);
            }
        }
        array_push($args, '-c:a', 'libmp3lame', '-b:a', $bitrate . 'k', '-ar', '44100', '-id3v2_version', '3', '-f', 'mp3', $outPath);

        return $args;
    }

    /**
     * ffmpeg command line (pure function, tested).
     *
     * @return string[]
     */
    public static function arguments(
        string $ffmpeg,
        string $voicePath,
        float $voiceDuration,
        ?string $bedPath,
        float $bedOffset,
        array $bed,
        array $output,
        string $outPath,
        array $metadata = []
    ): array {
        $fmt = static fn(float $value): string => rtrim(rtrim(sprintf('%.3F', $value), '0'), '.') ?: '0';
        $stereoVoice = 'aresample=44100,aformat=sample_fmts=fltp:channel_layouts=mono,pan=stereo|c0=c0|c1=c0';

        $args = [$ffmpeg, '-hide_banner', '-nostdin', '-y', '-i', $voicePath];

        if ($bedPath !== null) {
            $intro = (float)$bed['intro'];
            $outro = (float)$bed['outro'];
            $ramp = max(0.05, (float)$bed['ramp']);
            $total = $intro + $voiceDuration + $outro;
            $low = 10 ** (-(float)$bed['duck_db'] / 20);
            $gain = 10 ** ((float)$bed['bed_gain_db'] / 20);
            $duckStart = max(0.0, $intro - $ramp);
            $duckFull = $intro;
            $voiceEnd = $intro + $voiceDuration;
            $upEnd = $voiceEnd + $ramp;
            [$a, $b, $c, $d, $g] = [$fmt($duckStart), $fmt($duckFull), $fmt($voiceEnd), $fmt($upEnd), $fmt($low)];

            // 1 before the voice, down to g under it, back to 1 after it (linear ramps).
            $envelope = "if(lt(t,$a),1,if(lt(t,$b),1-(1-$g)*(t-$a)/($b-$a),if(lt(t,$c),$g,if(lt(t,$d),$g+(1-$g)*(t-$c)/($d-$c),1))))";
            if ($b === $a) {
                $envelope = "if(lt(t,$a),1,if(lt(t,$c),$g,if(lt(t,$d),$g+(1-$g)*(t-$c)/($d-$c),1)))";
            }

            $bedChain = 'aresample=44100,aformat=sample_fmts=fltp:channel_layouts=stereo'
                . ',atrim=0:' . $fmt($total) . ',asetpts=PTS-STARTPTS'
                . ',volume=' . $fmt($gain)
                . ",volume='" . $envelope . "':eval=frame";
            $fadeIn = min((float)$bed['fade_in'], $total);
            if ($fadeIn > 0) {
                $bedChain .= ',afade=t=in:st=0:d=' . $fmt($fadeIn);
            }
            $fadeOut = min((float)$bed['fade_out'], $total);
            if ($fadeOut > 0) {
                $bedChain .= ',afade=t=out:st=' . $fmt($total - $fadeOut) . ':d=' . $fmt($fadeOut);
            }

            if ($bedOffset > 0) {
                array_push($args, '-ss', $fmt($bedOffset));
            }
            array_push($args, '-stream_loop', '-1', '-i', $bedPath);

            $delay = (int)round($intro * 1000);
            $graph = '[1:a]' . $bedChain . '[bed];'
                . '[0:a]' . $stereoVoice . ($delay > 0 ? ',adelay=' . $delay . ':all=1' : '') . ',apad[voice];'
                . '[bed][voice]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[mix];'
                . '[mix]' . self::finalChain($output, $fmt) . '[out]';
        } else {
            $graph = '[0:a]' . $stereoVoice
                . ',adelay=' . (int)(self::VOICE_ONLY_LEAD * 1000) . ':all=1'
                . ',apad=pad_dur=' . $fmt(self::VOICE_ONLY_TAIL)
                . ',' . self::finalChain($output, $fmt) . '[out]';
        }

        array_push($args, '-filter_complex', $graph, '-map', '[out]', '-map_metadata', '-1');
        foreach ($metadata as $key => $value) {
            if ($value !== '') {
                array_push($args, '-metadata', $key . '=' . $value);
            }
        }
        array_push(
            $args,
            '-c:a',
            'libmp3lame',
            '-b:a',
            ((int)($output['bitrate'] ?? 192)) . 'k',
            '-ar',
            '44100',
            '-id3v2_version',
            '3',
            '-f',
            'mp3',
            $outPath
        );

        return $args;
    }

    private static function finalChain(array $output, callable $fmt): string
    {
        $chain = [];
        if (!empty($output['normalize'])) {
            $chain[] = 'loudnorm=I=' . $fmt((float)$output['lufs']) . ':TP=-1.5:LRA=11';
            $chain[] = 'aresample=44100';
        }
        $chain[] = 'alimiter=limit=0.97:level=disabled';

        return implode(',', $chain);
    }

    private function ffmpeg(): string
    {
        return Shell::which('ffmpeg') ?? throw new RuntimeException('ffmpeg est introuvable dans le conteneur AzuraCast.');
    }
}
