<?php

namespace App\Services\Video;

use App\Contracts\ResumableUploads;
use App\Enums\UploadStatus;
use App\Enums\VideoStatus;
use App\Jobs\ProcessVideo;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalUploads implements ResumableUploads
{
    public function __construct(private MediaLock $lock) {}

    public function create(User $user, array $data): UploadSession
    {
        return $this->lock->run('quota', function () use ($user, $data) {
            // Include expired-but-not-cleaned reservations and soft-deleted originals.
            $used = app(VideoStorageBudget::class)->used();
            abort_if($used + $data['size'] > config('video.storage_limit_bytes'), 422, 'Der Videospeicher ist voll.');
            $id = (string) Str::uuid();

            return UploadSession::create([
                'id' => $id, 'user_id' => $user->id, 'original_filename' => $data['filename'],
                'title' => $data['title'], 'mime_type' => $data['mime_type'], 'expected_size' => $data['size'],
                'chunk_bytes' => config('video.chunk_bytes'), 'temporary_path' => 'uploads/'.$id.'.part',
                'status' => UploadStatus::Active, 'expires_at' => now()->addHours(config('video.upload_hours')),
            ]);
        });
    }

    public function append(UploadSession $upload, int $offset, int $length, string $checksum, mixed $input): UploadSession
    {
        return $this->lock->run($upload->id, function () use ($upload, $offset, $length, $checksum, $input) {
            $upload->refresh();
            $this->assertActive($upload);
            abort_unless($offset === $upload->offset, 409, 'Der Upload-Fortschritt weicht ab. Prüfe vor dem nächsten Versuch den bestätigten Fortschritt.');
            abort_unless($length > 0 && $length <= $upload->chunk_bytes && $offset + $length <= $upload->expected_size, 422, 'Ungültige Blocklänge.');
            $disk = Storage::disk(config('video.disk'));
            $disk->makeDirectory('uploads');
            $file = fopen($disk->path($upload->temporary_path), 'c+b');
            if (! $file) {
                throw new \RuntimeException('Cannot open upload.');
            }
            try {
                if (! chmod($disk->path($upload->temporary_path), 0600)) {
                    throw new \RuntimeException('Cannot protect temporary upload.');
                }
                // A crash may leave uncommitted bytes. The DB offset is authoritative.
                abort_if(fstat($file)['size'] < $offset, 409, 'Die temporäre Datei ist unvollständig. Starte einen neuen Upload.');
                if (! ftruncate($file, $offset) || fseek($file, $offset) !== 0) {
                    throw new \RuntimeException('Cannot seek upload.');
                }
                $hash = hash_init('sha256');
                $written = 0;
                while (! feof($input) && $written <= $length) {
                    $buffer = fread($input, min(65536, $length - $written + 1));
                    if ($buffer === false) {
                        throw new \RuntimeException('Cannot read chunk.');
                    }
                    if ($buffer === '') {
                        break;
                    }
                    $written += strlen($buffer);
                    abort_if($written > $length, 422, 'Der Datenblock überschreitet die angegebene Länge.');
                    hash_update($hash, $buffer);
                    $remaining = $buffer;
                    while ($remaining !== '') {
                        $n = fwrite($file, $remaining);
                        if (! $n) {
                            throw new \RuntimeException('Cannot write chunk.');
                        }
                        $remaining = substr($remaining, $n);
                    }
                }
                abort_unless($written === $length && hash_equals(strtolower($checksum), hash_final($hash)), 422, 'Blocklänge oder Prüfsumme stimmt nicht überein.');
                if (! fflush($file) || ! fsync($file)) {
                    throw new \RuntimeException('Cannot flush upload.');
                }
                $upload->offset = $offset + $written;
                $upload->save();
            } catch (\Throwable $e) {
                ftruncate($file, $offset);
                fflush($file);
                throw $e;
            } finally {
                fclose($file);
            }

            return $upload;
        });
    }

    public function finish(UploadSession $upload): Video
    {
        abort_unless(config('queue.default') === 'database' && in_array(config('queue.connections.database.connection'), [null, config('database.default')], true), 503, 'Die Verarbeitung erfordert die Datenbank-Warteschlange auf der Anwendungsdatenbank.');

        return $this->lock->run($upload->id, function () use ($upload) {
            $upload->refresh();
            if ($upload->status === UploadStatus::Completed) {
                return Video::findOrFail($upload->video_id);
            }
            $this->assertActive($upload);
            abort_unless($upload->offset === $upload->expected_size, 409, 'Der Upload ist noch nicht vollständig.');
            $disk = Storage::disk(config('video.disk'));
            $original = 'originals/'.$upload->id;
            // A rename before a failed DB commit is recoverable by retrying finalization.
            $source = $disk->exists($original) ? $original : $upload->temporary_path;
            abort_unless($disk->exists($source) && $disk->size($source) === $upload->expected_size, 422, 'Die Grösse der zusammengesetzten Datei stimmt nicht überein.');
            $handle = $disk->readStream($source);
            try {
                $sample = fread($handle, 262144);
            } finally {
                fclose($handle);
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);
            abort_unless(in_array($mime, config('video.mime_types'), true), 422, 'Nicht unterstützter Medieninhalt. Starte erneut mit MP4, MOV, WebM oder MKV.');
            if ($source !== $original) {
                $disk->move($source, $original);
            }

            return $this->lock->run('quota', fn () => DB::transaction(function () use ($upload, $original, $mime) {
                $video = Video::create([
                    'uuid' => $upload->id, 'title' => $upload->title, 'original_filename' => $upload->original_filename,
                    'mime_type' => $mime, 'file_size' => $upload->expected_size, 'storage_provider' => 'local',
                    'storage_path' => $original, 'status' => VideoStatus::Processing, 'uploaded_by' => $upload->user_id,
                    'prototype_available' => true,
                ]);
                $upload->update(['status' => UploadStatus::Completed, 'video_id' => $video->id]);
                // Database queue insertion and video transition commit atomically on the same DB.
                ProcessVideo::dispatch($video->id)->beforeCommit();

                return $video;
            }));
        });
    }

    private function assertActive(UploadSession $upload): void
    {
        abort_unless($upload->status === UploadStatus::Active && $upload->expires_at->isFuture(), 410, 'Der Upload ist abgelaufen oder geschlossen. Starte einen neuen Upload.');
    }
}
