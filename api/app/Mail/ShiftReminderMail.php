<?php

namespace App\Mail;

use App\Mail\Concerns\FormatsMarkdownText;
use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 前日リマインド（F-22）。確定している勤務の日時・拠点・職種をワーカーに前日に知らせる。
 *
 * 送るのは SendShiftReminderMail ジョブだけ（ジョブの中で send() する）。Mailable 自体はキューに入れないので
 * ShouldQueue / SerializesModels を付けない。応募は worker と jobPosting.location を読み込んで渡す。
 * 件名に「明日」と書かないのは、--date で別の日を指定して手で実行することもあるため（日付をそのまま書く）。
 * ワーカーはログインできないので本文にリンクを置かない（ApplicationConfirmedMail と同じ方針）。
 * https://readouble.com/laravel/13.x/ja/mail.html
 */
class ShiftReminderMail extends Mailable
{
    use FormatsMarkdownText;

    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        $posting = $this->application->jobPosting;

        return new Envelope(
            subject: '【リマインド】'.$posting->work_date->isoFormat('M月D日（ddd）').$posting->job_title.'の勤務のお知らせ',
        );
    }

    public function content(): Content
    {
        $posting = $this->application->jobPosting;

        return new Content(
            markdown: 'mail.shift-reminder',
            with: [
                'workerName' => self::singleLine($this->application->worker->name),
                'workDate' => $posting->work_date->isoFormat('YYYY年M月D日（ddd）'),
                'startTime' => substr($posting->start_time, 0, 5),
                'endTime' => substr($posting->end_time, 0, 5),
                'locationName' => self::tableCell($posting->location->name),
                'locationAddress' => self::tableCell($posting->location->address),
                'jobTitle' => self::tableCell($posting->job_title),
            ],
        );
    }
}
