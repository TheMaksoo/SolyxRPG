<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TesterRegistrationNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your Solyx RPG tester application has been received')
            ->line('Thank you for registering! Your application to join the Solyx RPG closed beta has been received.')
            ->line('A Game Master will review your application and you will receive an email once a decision has been made.')
            ->line('If you have any questions, feel free to reach out to the team.');
    }
}
