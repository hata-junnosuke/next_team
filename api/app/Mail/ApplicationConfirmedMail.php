<?php

namespace App\Mail;

use App\Mail\Concerns\FormatsMarkdownText;
use App\Models\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 確定通知（F-20）。ワーカー宛てに日時・拠点・職種・時給を知らせる（Rails の ActionMailer に相当）。
 *
 * 送るのは SendApplicationConfirmedMail ジョブだけ（ジョブの中で send() する）。Mailable 自体はキューに入れないので
 * ShouldQueue / SerializesModels を付けない。時給は募集の今の値ではなく、確定時に固定した confirmed_hourly_wage。
 * 応募は worker と jobPosting.location を読み込んで渡す。
 * ワーカーはログインできないので本文にリンクを置かない（F-21・F-22 も同じ方針。管理画面の URL を外部に配らない）。
 * https://readouble.com/laravel/13.x/ja/mail.html
 */
class ApplicationConfirmedMail extends Mailable
{
    use FormatsMarkdownText;

    public function __construct(public Application $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【確定】'.$this->application->jobPosting->job_title.'の勤務が確定しました');
    }

    public function content(): Content
    {
        $posting = $this->application->jobPosting;

        return new Content(
            markdown: 'mail.application-confirmed',
            with: [
                'workerName' => self::singleLine($this->application->worker->name),
                'workDate' => $posting->work_date->isoFormat('YYYY年M月D日（ddd）'),
                'startTime' => substr($posting->start_time, 0, 5),
                'endTime' => substr($posting->end_time, 0, 5),
                'locationName' => self::tableCell($posting->location->name),
                'locationAddress' => self::tableCell($posting->location->address),
                'jobTitle' => self::tableCell($posting->job_title),
                'hourlyWage' => number_format($this->application->confirmed_hourly_wage),
            ],
        );
    }
}
