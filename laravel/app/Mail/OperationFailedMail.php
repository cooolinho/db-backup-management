<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperationFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $operation,
        public readonly string $database,
        public readonly string $error,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name')."] {$this->operation} fehlgeschlagen: {$this->database}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.operation-failed',
            with: [
                'operation' => $this->operation,
                'database' => $this->database,
                'error' => $this->error,
                'url' => config('app.url'),
            ],
        );
    }
}
