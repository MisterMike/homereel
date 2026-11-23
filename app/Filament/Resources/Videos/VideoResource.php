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
            TextInput::make('title')->required()->maxLength(255),
            Select::make('album_id')->relationship('album', 'name')->searchable()->preload()->exists('albums', 'id')->label('Album')->placeholder('No album'),
            Textarea::make('description')->maxLength(10000)->columnSpanFull(),
            Toggle::make('prototype_available')->label('Available to family members')->helperText('Members can watch this video once processing finishes.'),
            Section::make('Optimized playback')->columnSpanFull()->schema([
                TextEntry::make('optimization_status')->label('MP4 copy')->formatStateUsing(fn (?string $state): string => ucfirst(str_replace('_', ' ', $state ?? 'not_requested'))),
                TextEntry::make('optimized_file_size')->label('Optimized size')->formatStateUsing(fn ($state): string => number_format($state / 1048576, 1).' MiB')->placeholder('No copy yet'),
                TextEntry::make('optimization_error')->label('Optimization')->color('danger')->visible(fn (?Video $record): bool => filled($record?->optimization_error)),
            ])->description('A separate H.264/AAC MP4 up to 720p. The player offers Optimized and Original once it is ready.'),
            Section::make('Preview thumbnail')->columnSpanFull()->schema([
                ImageEntry::make('thumbnail_preview')->label('Current preview')
                    ->state(fn (?Video $record): ?string => $record?->thumbnail_path ? route('videos.thumbnail', $record) : null)
                    ->imageWidth(320)->imageHeight(180)->extraImgAttributes(['style' => 'max-width: 100%; height: auto; aspect-ratio: 16/9; object-fit: cover;'])->placeholder('No preview generated yet.'),
                TextInput::make('thumbnail_offset_seconds')->label('Thumbnail time (seconds)')
                    ->numeric()->step(0.1)->minValue(0)
                    ->maxValue(fn (?Video $record): ?float => $record?->duration_seconds ? max(0, $record->duration_seconds - 0.001) : null)
                    ->disabled(fn (?Video $record): bool => ! config('video.thumbnails') || $record?->status !== VideoStatus::Ready)
                    ->helperText(fn (): string => config('video.thumbnails')
                        ? 'Leave empty for automatic selection: 5 seconds, or one third of a short clip. Saving a changed time queues a new preview. Refresh after processing to see it.'
                        : 'Thumbnail generation is disabled on this server.'),
                TextEntry::make('thumbnail_error')->label('Preview generation')->color('danger')
                    ->visible(fn (?Video $record): bool => filled($record?->thumbnail_error)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(), TextColumn::make('album.name')->placeholder('No album')->sortable(), TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
            TextColumn::make('file_size')->label('Size')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1).' MiB'),
            TextColumn::make('optimization_status')->label('MP4 copy')->badge(),
            TextColumn::make('duration_label')->label('Duration'), TextColumn::make('status')->badge(),
            TextColumn::make('browser_compatible')->label('MP4 playback')->formatStateUsing(fn ($state) => $state ? 'Compatible' : 'Check format'),
        ])->filters([SelectFilter::make('album')->relationship('album', 'name')->searchable()->preload()])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('watch')->url(fn (Video $record) => route('videos.show', $record))->icon('heroicon-o-play'),
            EditAction::make(), DeleteAction::make()->requiresConfirmation()->modalDescription('The video becomes unavailable immediately. Its original is retained until an operator explicitly purges deleted videos.'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVideos::route('/'), 'edit' => EditVideo::route('/{record}/edit')];
    }
}
