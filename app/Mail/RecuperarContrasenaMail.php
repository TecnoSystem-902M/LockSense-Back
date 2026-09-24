<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecuperarContrasenaMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $enlace;
    public string $nombreUsuario;

    public function __construct(string $enlace, string $nombreUsuario)
    {
        $this->enlace = $enlace;
        $this->nombreUsuario = $nombreUsuario;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Restablece tu contraseña - LockSense',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recuperar-contrasena',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}