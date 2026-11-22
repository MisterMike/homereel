<?php

namespace App\Filament\Resources\Videos;

use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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
