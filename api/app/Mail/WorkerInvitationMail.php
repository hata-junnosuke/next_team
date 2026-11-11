<?php

namespace App\Mail;

use App\Mail\Concerns\FormatsMarkdownText;
use App\Models\WorkerInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 招待メール（F-26）。ワーカー宛てに、アプリの初回登録に使う招待コード・有効期限・企業コードを知らせる（Rails の ActionMailer に相当）。
 *
 * 送るのは SendWorkerInvitationMail ジョブだけ（ジョブの中で send() する）。**ShouldQueue を付けない**: 付けると
 * Mail::to()->send() でもキューに積まれ、ペイロードに招待コード（復号した値）が入る（rules/common/security.md）。
 * キューに入れないので SerializesModels も付けない（お手本の ApplicationConfirmedMail と同じ）。招待は worker と company を読み込んで渡す。
 * コードは code 列（encrypted cast）から、組み立てるときに読む。本文にリンクを置かない（ほかのワーカー宛てのメールと同じ方針）。
 * https://readouble.com/laravel/13.x/ja/mail.html
 */
class WorkerInvitationMail extends Mailable
{
    use FormatsMarkdownText;

    public function __construct(public WorkerInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【招待】'.config('app.name').' のワーカーアプリの初回登録のご案内');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.worker-invitation',
            with: [
                'workerName' => self::singleLine($this->invitation->worker->name),
                // 同じメールで別の企業にも登録できるので、どの企業からの招待かを書く（企業名は管理者が入れる値なので表のセル用にエスケープ）
                'companyName' => self::tableCell($this->invitation->company->name),
                'companyCode' => $this->invitation->company->code,
                'invitationCode' => $this->invitation->code,
                'expiresAt' => $this->invitation->expires_at->isoFormat('YYYY年M月D日（ddd） HH:mm'),
            ],
        );
    }
}
