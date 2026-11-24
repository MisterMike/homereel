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
        return 'Alle gehören zu einer gemeinsamen Mediathek. Lade Personen ein, wähle ihre Rolle oder deaktiviere ihren Zugang.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteUser')->label('Person einladen')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->label('E-Mail-Adresse')->email()->required()->maxLength(255)->unique(User::class, 'email'),
                Select::make('role')->label('Rolle')->options(User::roles())->default('viewer')->required()
                    ->helperText('Zuschauer sehen freigegebene Videos. Mitwirkende verwalten ihre eigenen Uploads. Administratoren verwalten alle Videos, Alben und Personen.'),
            ])->action(function (array $data): void {
                app(UserInvitations::class)->invite(auth()->user(), $data);
                Notification::make()->title('Einladung vorgemerkt')->body('Eine E-Mail mit einem sieben Tage gültigen Einladungslink wird versendet.')->success()->send();
            }),
        ];
    }
}
