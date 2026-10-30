<?php

namespace App\Mail;

use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayrollPayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $company,
        public PayrollRun $run,
        public PayrollRunLine $line,
        public array $snapshot,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confidential payslip · '.$this->run->period_start->format('F Y'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payroll-payslip');
    }
}
