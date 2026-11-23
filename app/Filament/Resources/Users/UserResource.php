<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\UserInvitations;
use App\Services\UserManagement;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->disabled()->dehydrated(false),
            Select::make('role')->options(User::roles())->required()
                ->helperText('Viewers watch shared videos. Contributors also upload and manage their own videos. Administrators manage the whole library and users.'),
            Toggle::make('is_active')->label('Access enabled')->required()
                ->helperText('Disabling access also blocks existing sessions and pending invitations.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('role')->formatStateUsing(fn (string $state): string => User::roles()[$state])->badge(),
            TextColumn::make('is_active')->label('Access')->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')->badge(),
            TextColumn::make('invitation_status')->label('Invitation')->state(fn (User $record): string => $record->invitation_token === null ? 'Accepted / existing account' : ($record->invitation_expires_at?->isFuture() ? 'Pending' : 'Expired')),
        ])->filters([
            SelectFilter::make('is_active')->label('Access')->options([1 => 'Enabled', 0 => 'Disabled']),
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
            Action::make('resendInvitation')->label('Resend invitation')->requiresConfirmation()
                ->visible(fn (User $record): bool => $record->is_active && $record->invitation_token !== null)
                ->modalDescription('Send a new invitation valid for seven days. Previous invitation links will stop working.')
                ->action(function (User $record): void {
                    app(UserInvitations::class)->resend(auth()->user(), $record);
                    Notification::make()->title('Invitation queued')->body('The new invitation will be sent by email.')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
