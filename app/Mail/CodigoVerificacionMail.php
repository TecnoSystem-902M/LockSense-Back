<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CodigoVerificacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $codigo;
    public string $nombreUsuario;

    public function __construct(string $codigo, string $nombreUsuario)
    {
        $this->codigo = $codigo;
        $this->nombreUsuario = $nombreUsuario;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu código de verificación - LockSense',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.codigo-verificacion',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}