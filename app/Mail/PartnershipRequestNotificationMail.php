<?php

namespace App\Mail;

use App\Models\PartnershipRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnershipRequestNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public PartnershipRequest $partnershipRequest
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouvelle demande de partenariat sur Renaissance Muntu',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.partnership-request-notification',
            with: [
                'name' => $this->partnershipRequest->name,
                'organization' => $this->partnershipRequest->organization,
                'email' => $this->partnershipRequest->email,
                'phone' => $this->partnershipRequest->phone,
                'partnershipType' => $this->partnershipRequest->partnership_type,
                'partnershipMessage' => $this->partnershipRequest->message,
                'receivedAt' => $this->partnershipRequest->created_at,
            ],
        );
    }
}
