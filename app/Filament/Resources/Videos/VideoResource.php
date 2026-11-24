<?php

namespace App\Filament\Resources\Videos;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

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
            Textarea::make('description')->label('Beschreibung')->maxLength(10000)->columnSpanFull(),
            Toggle::make('prototype_available')->label('Für Familienmitglieder verfügbar')->helperText('Familienmitglieder können dieses Video nach der Verarbeitung ansehen.'),
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
            TextColumn::make('file_size')->label('Grösse')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1, ',', '.').' MiB'),
            TextColumn::make('optimization_status')->label('MP4-Kopie')->formatStateUsing(fn (?string $state): string => __('statuses.'.($state ?? 'not_requested')))->badge(),
            TextColumn::make('duration_label')->label('Dauer'), TextColumn::make('status')->formatStateUsing(fn (VideoStatus $state): string => __('statuses.'.$state->value))->badge(),
            TextColumn::make('browser_compatible')->label('MP4-Wiedergabe')->formatStateUsing(fn ($state) => $state ? 'Kompatibel' : 'Format prüfen'),
        ])->filters([SelectFilter::make('album')->relationship('album', 'name')->searchable()->preload()])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('watch')->label('Ansehen')->url(fn (Video $record) => route('videos.show', $record))->icon('heroicon-o-play'),
            EditAction::make(), DeleteAction::make()->requiresConfirmation()->modalDescription('Das Video ist sofort nicht mehr verfügbar. Das Original bleibt erhalten, bis gelöschte Videos ausdrücklich auf dem Server bereinigt werden.'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVideos::route('/'), 'edit' => EditVideo::route('/{record}/edit')];
    }
}
