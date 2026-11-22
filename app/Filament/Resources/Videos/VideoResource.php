<?php

namespace App\Filament\Resources\Videos;

use App\Enums\VideoStatus;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('description')->maxLength(10000)->columnSpanFull(),
            Toggle::make('prototype_available')->label('Available to family members')->helperText('Members can watch this video once processing finishes.'),
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
            TextColumn::make('title')->searchable(), TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
            TextColumn::make('file_size')->label('Size')->formatStateUsing(fn ($state) => number_format($state / 1048576, 1).' MiB'),
            TextColumn::make('duration_label')->label('Duration'), TextColumn::make('status')->badge(),
            TextColumn::make('browser_compatible')->label('MP4 playback')->formatStateUsing(fn ($state) => $state ? 'Compatible' : 'Check format'),
        ])->defaultSort('created_at', 'desc')->recordActions([
            Action::make('watch')->url(fn (Video $record) => route('videos.show', $record))->icon('heroicon-o-play'),
            EditAction::make(), DeleteAction::make()->requiresConfirmation()->modalDescription('The video becomes unavailable immediately. Its original is retained until an operator explicitly purges deleted videos.'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVideos::route('/'), 'edit' => EditVideo::route('/{record}/edit')];
    }
}
