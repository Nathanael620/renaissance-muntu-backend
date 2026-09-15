<?php

namespace App\Mail;

use App\Models\Donation;
use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Donation $donation,
        public Receipt $receipt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre reçu officiel de don / Your official donation receipt',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.donation-receipt',
            with: [
                'donation' => $this->donation,
                'receipt' => $this->receipt,
            ],
        );
    }

    /**
     * Attach the PDF directly from Laravel's private local disk.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => Storage::disk('local')->get($this->receipt->file_path),
                basename($this->receipt->file_path),
            )->withMime('application/pdf'),
        ];
    }
}
