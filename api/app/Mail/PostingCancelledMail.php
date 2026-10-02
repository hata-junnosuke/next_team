<?php

namespace App\Mail;

use App\Mail\Concerns\FormatsMarkdownText;
use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 中止通知（F-21）。確定していた募集が中止になり、企業都合でキャンセルになったことをワーカーに知らせる。
 *
 * 送るのは SendPostingCancelledMail ジョブだけ（ジョブの中で send() する）。Mailable 自体はキューに入れないので
 * ShouldQueue / SerializesModels を付けない。応募は worker と jobPosting.location を読み込んで渡す。
 * ワーカーはログインできないので本文にリンクを置かない（ApplicationConfirmedMail と同じ方針）。
 * https://readouble.com/laravel/13.x/ja/mail.html
 */
class PostingCancelledMail extends Mailable
{
    use FormatsMarkdownText;

    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【中止】'.$this->application->jobPosting->job_title.'の募集が中止になりました');
    }

    public function content(): Content
    {
        $posting = $this->application->jobPosting;

        return new Content(
            markdown: 'mail.posting-cancelled',
            with: [
                'workerName' => self::singleLine($this->application->worker->name),
                'workDate' => $posting->work_date->isoFormat('YYYY年M月D日（ddd）'),
                'startTime' => substr($posting->start_time, 0, 5),
                'endTime' => substr($posting->end_time, 0, 5),
                'locationName' => self::tableCell($posting->location->name),
                'locationAddress' => self::tableCell($posting->location->address),
                'jobTitle' => self::tableCell($posting->job_title),
                'cancelReason' => $this->application->cancel_reason?->label(),
            ],
        );
    }
}
