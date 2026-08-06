<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TesterApprovedNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = rtrim(config('app.url'), '/').'/dashboard';

        return (new MailMessage())
            ->subject('Your Solyx RPG tester application has been approved!')
            ->line('Great news — your application to join the Solyx RPG closed beta has been approved!')
            ->action('Play Now', $url)
            ->line('Welcome to the team. We hope you enjoy the game and look forward to your feedback.');
    }
}
