<?php

namespace App\Filament\Pages;

use App\Jobs\DetectVideoRecordingDate;
use App\Jobs\GenerateVideoThumbnail;
use App\Jobs\OptimizeVideo;
use App\Jobs\PrepareWebVideo;
use App\Jobs\ProcessVideo;
use App\Notifications\LibraryInvitation;
use App\Notifications\VideoReady;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use stdClass;

class QueueOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.queue-overview';

    protected static ?string $title = 'Warteschlange';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 80;

    public bool $showFailed = false;

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_admin && auth()->user()?->canUploadVideos());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array{pending: int, reserved: int, failed: int} */
    #[Computed]
    public function summary(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'pending' => $this->jobsQuery()->whereNull('reserved_at')->count(),
            'reserved' => $this->jobsQuery()->whereNotNull('reserved_at')->count(),
            'failed' => $this->failedJobsQuery()->count(),
        ];
    }

    public function getSubheading(): ?string
    {
        $counts = $this->summary;

        return number_format($counts['pending'], 0, ',', '.').' wartend oder geplant · '
            .number_format($counts['reserved'], 0, ',', '.').' reserviert · '
            .number_format($counts['failed'], 0, ',', '.').' fehlgeschlagen. Aktualisierung alle 10 Sekunden.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activeJobs')->label('Offene Aufträge')->color(fn (): string => $this->showFailed ? 'gray' : 'primary')
                ->action(function (): void {
                    $this->changeList(false);
                }),
            Action::make('failedJobs')->label('Fehlgeschlagene Aufträge')->color(fn (): string => $this->showFailed ? 'primary' : 'gray')
                ->action(function (): void {
                    $this->changeList(true);
                }),
            Action::make('refresh')->label('Aktualisieren')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);
                    $this->flushCachedTableRecords();
                }),
        ];
    }

    private function changeList(bool $failed): void
    {
        abort_unless(static::canAccess(), 403);
        $this->showFailed = $failed;
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => $this->showFailed ? 'Fehlgeschlagene Aufträge' : 'Offene Aufträge')
            ->description(fn (): string => $this->showFailed
                ? 'Fehlgeschlagene Aufträge bleiben hier erhalten. Weitere Fehlerdetails stehen im privaten Anwendungsprotokoll.'
                : 'Reserviert bedeutet, dass ein Worker den Auftrag übernommen hat. Eine abgelaufene Reservierung kann auf einen unterbrochenen Worker hinweisen. Wenn wartende Aufträge liegen bleiben, prüfe den Cronjob und den Queue-Worker.')
            ->records(fn (int $page, int $recordsPerPage): LengthAwarePaginator => $this->records($page, $recordsPerPage))
            ->columns([
                TextColumn::make('id')->label('Nr.'),
                TextColumn::make('task')->label('Aufgabe')->wrap(),
                TextColumn::make('queue')->label('Warteschlange')->formatStateUsing(fn (string $state): string => match ($state) {
                    config('queue.connections.database.queue') => 'Standard',
                    config('queue.connections.transcoding.queue') => 'MP4-Konvertierung',
                    'standard' => 'Web-Version (Priorität)',
                    default => $state,
                }),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'waiting' => 'Wartend', 'delayed' => 'Geplant', 'reserved' => 'Reserviert',
                    'stale' => 'Reservierung abgelaufen', 'failed' => 'Fehlgeschlagen',
                })->color(fn (string $state): string => match ($state) {
                    'waiting' => 'warning', 'delayed' => 'gray', 'reserved' => 'info',
                    'stale', 'failed' => 'danger',
                }),
                TextColumn::make('attempts')->label('Versuche')->placeholder('—')->visible(fn (): bool => ! $this->showFailed),
                TextColumn::make('created_at')->label(fn (): string => $this->showFailed ? 'Fehlgeschlagen am' : 'Eingereiht am')->dateTime('d.m.Y H:i:s'),
                TextColumn::make('available_at')->label('Fällig ab')->dateTime('d.m.Y H:i:s')->visible(fn (): bool => ! $this->showFailed),
                TextColumn::make('reserved_at')->label('Übernommen am')->dateTime('d.m.Y H:i:s')->placeholder('Noch nicht übernommen')->visible(fn (): bool => ! $this->showFailed),
                TextColumn::make('details')->label('Hinweis')->wrap()->visible(fn (): bool => $this->showFailed),
            ])
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)->enforcePaginationPageOptions()
            ->poll('10s')
            ->emptyStateHeading(fn (): string => $this->showFailed ? 'Keine fehlgeschlagenen Aufträge' : 'Keine offenen Aufträge')
            ->emptyStateDescription(fn (): string => $this->showFailed ? 'Es sind keine fehlgeschlagenen Hintergrundaufträge gespeichert.' : 'Neue Uploads, Vorschaubilder, Datumserkennung, MP4-Konvertierungen und Einladungen erscheinen hier, solange sie auf die Verarbeitung warten oder reserviert sind. Erfolgreich erledigte Aufträge verschwinden automatisch.');
    }

    public function records(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        abort_unless(static::canAccess(), 403);
        $recordsPerPage = in_array($recordsPerPage, [10, 25, 50], true) ? $recordsPerPage : 10;
        $records = $this->showFailed
            ? $this->failedJobsQuery()->orderByDesc('failed_at')->orderByDesc('id')->paginate($recordsPerPage, ['id', 'queue', 'payload', 'exception', 'failed_at'], 'page', max(1, $page))
            : $this->jobsQuery()->orderBy('created_at')->orderBy('id')->paginate($recordsPerPage, ['id', 'queue', 'payload', 'attempts', 'created_at', 'available_at', 'reserved_at'], 'page', max(1, $page));

        $records->through(function (stdClass $record): array {
            return [
                'key' => ($this->showFailed ? 'failed-' : 'job-').$record->id,
                'id' => $record->id,
                'task' => $this->taskLabel($record->payload),
                'queue' => $record->queue,
                'status' => $this->showFailed ? 'failed' : $this->jobStatus($record),
                'attempts' => $record->attempts ?? null,
                'created_at' => $this->showFailed ? $record->failed_at : Carbon::createFromTimestamp($record->created_at),
                'available_at' => isset($record->available_at) ? Carbon::createFromTimestamp($record->available_at) : null,
                'reserved_at' => isset($record->reserved_at) ? Carbon::createFromTimestamp($record->reserved_at) : null,
                'details' => $this->showFailed ? $this->failureLabel($record->exception) : null,
            ];
        });

        return $records;
    }

    private function jobsQuery(): Builder
    {
        return DB::connection(config('queue.connections.database.connection'))->table(config('queue.connections.database.table'));
    }

    private function failedJobsQuery(): Builder
    {
        return DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'));
    }

    private function jobStatus(stdClass $job): string
    {
        if ($job->reserved_at !== null) {
            $connection = in_array($job->queue, ['standard', config('queue.connections.transcoding.queue')], true) ? 'transcoding' : 'database';
            $retryAfter = (int) config('queue.connections.'.$connection.'.retry_after');

            return $job->reserved_at + $retryAfter <= now()->timestamp ? 'stale' : 'reserved';
        }

        return $job->available_at > now()->timestamp ? 'delayed' : 'waiting';
    }

    private function taskLabel(string $payload): string
    {
        $data = json_decode($payload, true, 16);

        return match (is_array($data) ? ($data['displayName'] ?? '') : '') {
            ProcessVideo::class => 'Video prüfen',
            GenerateVideoThumbnail::class => 'Vorschaubild erstellen',
            DetectVideoRecordingDate::class => 'Aufnahmedatum erkennen',
            OptimizeVideo::class => 'HD-MP4 erstellen',
            PrepareWebVideo::class => 'Web-Version erstellen',
            VideoReady::class => 'Video-bereit-E-Mail versenden',
            LibraryInvitation::class => 'Einladungs-E-Mail versenden',
            SendQueuedNotifications::class => 'E-Mail versenden',
            default => 'Hintergrundauftrag',
        };
    }

    private function failureLabel(string $exception): string
    {
        return match (true) {
            str_contains($exception, 'TimeoutExceededException') => 'Zeitlimit überschritten',
            str_contains($exception, 'MaxAttemptsExceededException') => 'Maximale Anzahl Versuche erreicht',
            str_contains($exception, 'TransportException') => 'E-Mail-Versand fehlgeschlagen',
            default => 'Fehler bei der Verarbeitung',
        };
    }
}
