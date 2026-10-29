<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecognitionAvailable extends Mailable
{
    use Queueable, SerializesModels;

    public $teacherName;
    public $announcementTitle;
    public $stageLabel;
    public ?int $recognitionId;

    public function __construct($teacherName, $announcementTitle, ?string $stage = null, ?int $recognitionId = null)
    {
        $this->recognitionId = $recognitionId;
        $this->teacherName = $teacherName;
        $this->announcementTitle = $announcementTitle;
        $this->stageLabel = $stage === 'national' ? 'Etapa nacional' : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu Reconocimiento está disponible - Sistema de Becas TecNM',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recognition-available',
        );
    }

    public function attachments(): array
    {
        if (!$this->recognitionId) return [];
        $recognition = \App\Models\Recognition::publishable()->with('user')->findOrFail($this->recognitionId);
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $recognition->identifier).'.pdf';
        return [Attachment::fromData(
            fn () => app(\App\Services\PdfGenerationService::class)->generateTeacherRecognitionPdf($recognition, $recognition->user)->getContent(),
            $filename
        )->withMime('application/pdf')];
    }
}
