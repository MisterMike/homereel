<?php

namespace App\Filament\Resources\Videos;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Video;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $modelLabel = 'Video';

    protected static ?string $pluralModelLabel = 'Videos';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()->is_contributor && ! auth()->user()->is_admin) {
            $query->where('uploaded_by', auth()->id());
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Titel')->required()->maxLength(255),
            Select::make('album_id')->relationship('album', 'name')->searchable()->preload()->exists('albums', 'id')->label('Album')->placeholder('Kein Album'),
            DatePicker::make('recorded_on')->label('Aufnahmedatum')->nullable()->minDate('1000-01-01')->maxDate(today())
                ->helperText('Wird nach Möglichkeit aus der Videodatei erkannt und kann korrigiert werden. Metadaten können auch das Exportdatum enthalten. Ohne Aufnahmedatum gilt für die Sortierung das Upload-Datum.'),
            Textarea::make('description')->label('Beschreibung')->maxLength(10000)->columnSpanFull(),
            Toggle::make('prototype_available')->label('Für Familienmitglieder verfügbar')->default(true)->helperText('Neue Uploads sind standardmässig nach der Verarbeitung für deine Familie verfügbar. Deaktiviere dies, um ein Video nur für dich und Administratoren sichtbar zu machen.'),
            Section::make('Optimierte Wiedergabe')->columnSpanFull()->schema([
                TextEntry::make('optimization_status')->label('MP4-Kopie')->formatStateUsing(fn (?string $state): string => __('statuses.'.($state ?? 'not_requested'))),
                TextEntry::make('optimized_file_size')->label('Grösse der optimierten Kopie')->formatStateUsing(fn ($state): string => number_format($state / 1048576, 1, ',', '.').' MiB')->placeholder('Noch keine Kopie'),
                TextEntry::make('optimization_error')->label('Optimierung')->color('danger')->visible(fn (?Video $record): bool => filled($record?->optimization_error)),
            ])->description('Eine separate H.264/AAC-MP4-Datei mit bis zu 720p. Sobald sie bereit ist, bietet der Player die Auswahl zwischen optimierter Version und Original.'),
            Section::make('Vorschaubild')->columnSpanFull()->schema([
                ImageEntry::make('thumbnail_preview')->label('Aktuelles Vorschaubild')
                    ->state(fn (?Video $record): ?string => $record?->thumbnail_path ? route('videos.thumbnail', $record) : null)
                    ->imageWidth(320)->imageHeight(180)->extraImgAttributes(['style' => 'max-width: 100%; height: auto; aspect-ratio: 16/9; object-fit: cover;'])->placeholder('Noch kein Vorschaubild erstellt.'),
                TextInput::make('thumbnail_offset_seconds')->label('Zeitpunkt des Vorschaubilds (Sekunden)')
                    ->numeric()->step(0.1)->minValue(0)
                    ->maxValue(fn (?Video $record): ?float => $record?->duration_seconds ? max(0, $record->duration_seconds - 0.001) : null)
                    ->disabled(fn (?Video $record): bool => ! config('video.thumbnails') || $record?->status !== VideoStatus::Ready)
                    ->helperText(fn (): string => config('video.thumbnails')
                        ? 'Für automatische Auswahl leer lassen: 5 Sekunden oder ein Drittel eines kurzen Clips. Ein geänderter Zeitpunkt plant ein neues Vorschaubild ein. Lade die Seite nach der Verarbeitung neu.'
                        : 'Die Erstellung von Vorschaubildern ist auf diesem Server deaktiviert.'),
                TextEntry::make('thumbnail_error')->label('Vorschaubilderstellung')->color('danger')
                    ->visible(fn (?Video $record): bool => filled($record?->thumbnail_error)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Titel')->searchable(), TextColumn::make('album.name')->label('Album')->placeholder('Kein Album')->sortable(), TextColumn::make('created_at')->label('Hochgeladen')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('recorded_on')->label('Aufnahmedatum')->date('d.m.Y')->placeholder('Unbekannt')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->byRecordingDate($direction)),
            TextColumn::make('file_size')->label('Grösse')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1, ',', '.').' MiB'),
            TextColumn::make('optimization_status')->label('MP4-Kopie')->formatStateUsing(fn (?string $state): string => __('statuses.'.($state ?? 'not_requested')))->badge(),
            TextColumn::make('duration_label')->label('Dauer'), TextColumn::make('status')->formatStateUsing(fn (VideoStatus $state): string => __('statuses.'.$state->value))->badge(),
            TextColumn::make('view_count')->label('Aufrufe')->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.'))->sortable(),
            TextColumn::make('browser_compatible')->label('MP4-Wiedergabe')->formatStateUsing(fn ($state) => $state ? 'Kompatibel' : 'Format prüfen'),
        ])->filters([SelectFilter::make('album')->relationship('album', 'name')->searchable()->preload()])->defaultSort(fn (Builder $query): Builder => $query->byRecordingDate())->recordActions([
            Action::make('watch')->label('Ansehen')->url(fn (Video $record) => route('videos.show', $record))->icon('heroicon-o-play'),
            EditAction::make(), DeleteAction::make()->requiresConfirmation()->modalDescription('Das Video ist sofort nicht mehr verfügbar. Das Original bleibt erhalten, bis gelöschte Videos ausdrücklich auf dem Server bereinigt werden.'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVideos::route('/'), 'edit' => EditVideo::route('/{record}/edit')];
    }
}
