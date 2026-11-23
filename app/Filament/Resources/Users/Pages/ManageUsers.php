<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserInvitations;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return 'Everyone belongs to one shared library. Invite people, choose their role, or disable access.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteUser')->label('Invite user')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(User::class, 'email'),
                Select::make('role')->options(User::roles())->default('viewer')->required()
                    ->helperText('Viewers watch shared videos. Contributors manage their own uploads. Administrators manage all videos, albums and users.'),
            ])->action(function (array $data): void {
                app(UserInvitations::class)->invite(auth()->user(), $data);
                Notification::make()->title('Invitation queued')->body('An email with a seven-day invitation link will be sent.')->success()->send();
            }),
        ];
    }
}
