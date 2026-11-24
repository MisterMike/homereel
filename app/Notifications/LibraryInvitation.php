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
            ->action('Einladung annehmen', $this->url)
            ->line('Wähle dein Passwort, um loszulegen. Diese Einladung läuft in sieben Tagen ab.');
    }
}
