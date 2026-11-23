<?php

declare(strict_types=1);

namespace Plugin\AzuracastAnimateursVirtuels\Service;

use App\Cache\MediaListCache;
use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\StationMediaRepository;
use App\Entity\Repository\StationPlaylistMediaRepository;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Flysystem\StationFilesystems;
use App\Media\MediaProcessor;
use RuntimeException;
use Throwable;

/**
 * Puts chronicles in the station's media library with AzuraCast's own upload process (the
 * same as the "Upload" button, for local and remote S3 storage alike), manages their playlist
 * and deletes them after the retention period.
 *
 * Files created for each chronicle are listed in <data>/<station id>/chronicles/<chronicle id>.json:
 * the plugin only ever moves or deletes its own files.
 */
final class MediaLibrary
{
    public function __construct(
        private readonly StationFilesystems $filesystems,
        private readonly MediaProcessor $processor,
        private readonly StationMediaRepository $mediaRepo,
        private readonly StationPlaylistMediaRepository $spmRepo,
        private readonly StationQueueRepository $queueRepo,
        private readonly MediaListCache $mediaListCache,
        private readonly ReloadableEntityManagerInterface $em,
        private readonly Database $database,
        private readonly Jobs $jobs
    ) {
    }

    /**
     * Uploads a finished chronicle (the local file is kept) and returns where it went.
     *
     * @return array{media_id: int, path: string, size: int, created_at: int}
     */
    public function publish(Station $station, array $chronicle, string $localFile, string $fileName): array
    {
        $this->database->prepare();
        $storage = $station->media_storage_location;
        $size = (int)filesize($localFile);
        $storage->errorIfFull();
        if (!$storage->canHoldFile($size)) {
            throw new RuntimeException('Le stockage des médias de la station est plein.');
        }

        $fs = $this->filesystems->getMediaFilesystem($station);
        $folder = (string)$chronicle['folder'];

        // Creates "Chroniques", then "Chroniques/<name>"… if they do not exist yet.
        $current = '';
        foreach (explode('/', $folder) as $part) {
            $current = ltrim($current . '/' . $part, '/');
            try {
                if (!$fs->directoryExists($current)) {
                    $fs->createDirectory($current);
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Impossible de créer le dossier « ' . $current . ' » dans les médias : ' . $e->getMessage());
            }
        }

        $path = $folder . '/' . $fileName;
        for ($i = 2; $fs->fileExists($path) && $i < 100; $i++) {
            $path = $folder . '/' . preg_replace('/\.mp3$/', '-' . $i . '.mp3', $fileName);
        }

        // processAndUpload() deletes the file it uploads: it gets a copy.
        $copy = $this->jobs->dir((int)$station->id, 'tmp') . '/upload-' . bin2hex(random_bytes(4)) . '.mp3';
        if (!copy($localFile, $copy)) {
            throw new RuntimeException('Copie temporaire impossible avant l\'envoi dans les médias.');
        }
        try {
            $media = $this->processor->processAndUpload($storage, $path, $copy);
        } finally {
            @unlink($copy);
        }
        if (!$media instanceof StationMedia) {
            throw new RuntimeException('AzuraCast n\'a pas pu enregistrer le fichier « ' . $path . ' ».');
        }

        $storage->addStorageUsed($size);
        $this->em->persist($storage);
        $this->em->flush();
        $this->mediaListCache->clearCache($storage);

        $file = ['media_id' => (int)$media->id, 'path' => $path, 'size' => $size, 'created_at' => time()];
        $this->updateRegistry($station, (string)$chronicle['id'], static function (array $files) use ($file): array {
            $files[] = $file;

            return $files;
        });

        return $file;
    }

    /**
     * Puts the new version in the chronicle's playlist and takes the previous ones out of it.
     */
    public function replaceInPlaylist(Station $station, array $chronicle, int $mediaId): ?string
    {
        if (empty($chronicle['playlist_id'])) {
            return null;
        }
        $this->database->prepare();
        $playlist = $this->em->find(StationPlaylist::class, (int)$chronicle['playlist_id']);
        if (!$playlist instanceof StationPlaylist || $playlist->station->id !== $station->id) {
            throw new RuntimeException('La playlist de la chronique n\'existe plus.');
        }
        $media = $this->em->find(StationMedia::class, $mediaId)
            ?? throw new RuntimeException('Le fichier envoyé est introuvable dans les médias.');

        $this->spmRepo->addMediaToPlaylist($media, $playlist);
        $this->em->flush();

        foreach ($this->registry($station, (string)$chronicle['id']) as $file) {
            if ((int)$file['media_id'] === $mediaId) {
                continue;
            }
            $old = $this->em->find(StationMedia::class, (int)$file['media_id']);
            if (!$old instanceof StationMedia) {
                continue;
            }
            $spm = $this->spmRepo->findByPlaylistAndMedia($playlist, $old);
            if ($spm !== null) {
                $this->queueRepo->clearForMediaAndPlaylist($old, $playlist);
                $this->em->remove($spm);
            }
        }
        $this->em->flush();

        return $playlist->name;
    }

    /**
     * Takes out of the chronicle's playlist the versions older than its validity, when no newer
     * version replaced them (failed generation, no new content, server stopped…). The files stay
     * in the media library until they are deleted after "retention_days".
     *
     * @return array<int, array{path: string, created_at: int}> versions taken out of the playlist
     */
    public function withdrawExpired(Station $station, array $chronicle, int $now): array
    {
        $chronicleId = (string)$chronicle['id'];
        $expired = ChronicleSettings::expired($chronicle, $this->registry($station, $chronicleId), $now);
        if ($expired === []) {
            return [];
        }

        $this->database->prepare();
        $playlist = $this->em->find(StationPlaylist::class, (int)$chronicle['playlist_id']);
        $withdrawn = [];
        if ($playlist instanceof StationPlaylist && $playlist->station->id === $station->id) {
            foreach ($this->registry($station, $chronicleId) as $file) {
                if (!in_array((int)$file['media_id'], $expired, true)) {
                    continue;
                }
                $media = $this->em->find(StationMedia::class, (int)$file['media_id']);
                $spm = $media instanceof StationMedia ? $this->spmRepo->findByPlaylistAndMedia($playlist, $media) : null;
                if ($spm !== null) {
                    $this->queueRepo->clearForMediaAndPlaylist($media, $playlist);
                    $this->em->remove($spm);
                    $withdrawn[] = ['path' => (string)$file['path'], 'created_at' => (int)$file['created_at']];
                }
            }
            $this->em->flush();
        }

        // Marked once, so that the database is not asked again every minute.
        $this->updateRegistry($station, $chronicleId, static function (array $files) use ($expired, $now): array {
            foreach ($files as &$file) {
                if (in_array((int)$file['media_id'], $expired, true)) {
                    $file['withdrawn_at'] = $now;
                }
            }

            return $files;
        });

        return $withdrawn;
    }

    /**
     * Deletes this chronicle's files older than $days (never the newest one).
     *
     * @return int number of deleted files
     */
    public function cleanup(Station $station, array $chronicle, int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $files = $this->registry($station, (string)$chronicle['id']);
        if (count($files) < 2) {
            return 0;
        }
        usort($files, static fn(array $a, array $b): int => (int)$a['created_at'] <=> (int)$b['created_at']);
        $newest = array_pop($files);
        $limit = time() - $days * 86400;

        $this->database->prepare();
        $storage = $station->media_storage_location;
        $fs = $this->filesystems->getMediaFilesystem($station);
        $deleted = [];
        foreach ($files as $file) {
            if ((int)$file['created_at'] >= $limit) {
                continue;
            }
            $media = $this->em->find(StationMedia::class, (int)$file['media_id']);
            try {
                if ($media instanceof StationMedia && $media->path === $file['path']) {
                    $this->mediaRepo->remove($media, true, $fs);
                    $storage->removeStorageUsed((int)$file['size']);
                }
            } catch (Throwable) {
                continue; // retried at the next cleanup
            }
            $deleted[] = (int)$file['media_id'];
        }
        if ($deleted !== []) {
            $this->em->persist($storage);
            $this->em->flush();
            $this->mediaListCache->clearCache($storage);
            $this->updateRegistry($station, (string)$chronicle['id'], static fn(array $all): array => array_values(array_filter(
                $all,
                static fn(array $f): bool => !in_array((int)$f['media_id'], $deleted, true)
            )));
        }
        unset($newest);

        return count($deleted);
    }

    /** @return array<int, array{media_id: int, path: string, size: int, created_at: int}> */
    public function registry(Station $station, string $chronicleId): array
    {
        $file = $this->registryFile($station, $chronicleId);

        return is_file($file) ? (Store::load($file)['files'] ?? []) : [];
    }

    private function updateRegistry(Station $station, string $chronicleId, callable $change): void
    {
        $file = $this->registryFile($station, $chronicleId);
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage impossible de la liste des fichiers de la chronique.');
        }
        try {
            $files = is_file($file) ? (Store::load($file)['files'] ?? []) : [];
            Store::save($file, ['files' => array_values($change($files))]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function registryFile(Station $station, string $chronicleId): string
    {
        if (!preg_match('/^[a-z0-9]{6,32}$/', $chronicleId)) {
            throw new RuntimeException('Chronique invalide.');
        }

        return $this->jobs->dir((int)$station->id, 'chronicles') . '/' . $chronicleId . '.json';
    }
}
