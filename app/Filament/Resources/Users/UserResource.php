<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\UserInvitations;
use App\Services\UserManagement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'Person';

    protected static ?string $pluralModelLabel = 'Personen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Anzeigename')->required()->maxLength(255),
            TextInput::make('email')->label('E-Mail-Adresse')->email()->disabled()->dehydrated(false),
            Select::make('role')->label('Rolle')->options(User::roles())->required()
                ->helperText('Zuschauer sehen freigegebene Videos. Mitwirkende laden auch Videos hoch und verwalten ihre eigenen Videos. Administratoren verwalten die gesamte Mediathek und alle Personen.'),
            Toggle::make('is_active')->label('Zugang aktiviert')->required()
                ->helperText('Beim Deaktivieren werden auch bestehende Sitzungen und offene Einladungen gesperrt.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Name')->searchable(),
            TextColumn::make('email')->label('E-Mail-Adresse')->searchable(),
            TextColumn::make('role')->label('Rolle')->formatStateUsing(fn (string $state): string => User::roles()[$state])->badge(),
            TextColumn::make('is_active')->label('Zugang')->formatStateUsing(fn (bool $state): string => $state ? 'Aktiviert' : 'Deaktiviert')->badge(),
            TextColumn::make('invitation_status')->label('Einladung')->state(fn (User $record): string => $record->invitation_token === null ? 'Angenommen / bestehendes Konto' : ($record->invitation_expires_at?->isFuture() ? 'Offen' : 'Abgelaufen')),
        ])->filters([
            SelectFilter::make('is_active')->label('Zugang')->options([1 => 'Aktiviert', 0 => 'Deaktiviert']),
        ])->recordActions([
            EditAction::make()
                ->mutateRecordDataUsing(fn (array $data, User $record): array => [...$data, 'role' => $record->role])
                ->using(function (User $record, array $data, ManageUsers $livewire): User {
                    try {
                        return app(UserManagement::class)->update(auth()->user(), $record, $data);
                    } catch (ValidationException $exception) {
                        $statePath = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [$statePath.'.'.$key => $messages])->all());
                    }
                }),
            Action::make('resendInvitation')->label('Einladung erneut senden')->requiresConfirmation()
                ->visible(fn (User $record): bool => $record->is_active && $record->invitation_token !== null)
                ->modalDescription('Sende eine neue Einladung, die sieben Tage gültig ist. Bisherige Einladungslinks werden ungültig.')
                ->action(function (User $record): void {
                    app(UserInvitations::class)->resend(auth()->user(), $record);
                    Notification::make()->title('Einladung vorgemerkt')->body('Die neue Einladung wird per E-Mail versendet.')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
