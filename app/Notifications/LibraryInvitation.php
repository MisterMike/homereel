<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LibraryInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Du bist zu HomeReel eingeladen')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Du wurdest zur privaten Video-Mediathek deiner Familie eingeladen.')
            ->line('Deine E-Mail-Adresse für die Anmeldung: '.$notifiable->email)
            ->line('Lege über den folgenden Button dein Passwort fest. Danach bist du direkt angemeldet. Für spätere Anmeldungen verwendest du diese E-Mail-Adresse und dein Passwort.')
            ->action('Passwort festlegen', $this->url)
            ->line('Diese Einladung ist sieben Tage gültig.');
    }
}
