<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SitemapPingFailed extends Mailable
{
    use Queueable, SerializesModels;

    public array $details;

    /**
     * Create a new message instance.
     */
    public function __construct(array $details)
    {
        $this->details = $details;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = 'Sitemap ping failure for ' . ($this->details['sitemap'] ?? 'sitemap');

        return $this->subject($subject)
            ->view('emails.sitemap_ping_failed')
            ->with(['details' => $this->details]);
    }
}
