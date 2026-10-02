<?php

namespace App\Mail\Concerns;

/**
 * Markdown メールに差し込む利用者の入力（氏名・拠点名・住所・職種）を整える（Rails の concern に相当するトレイト）。
 *
 * Markdown::withSecuredEncoding()（AppServiceProvider）がエスケープするのは [ < > などで、| と改行はそのまま残る。
 * 改行は段落や見出しを作り、| は表の列を増やす（拠点名の | の後ろが別の列になって消える）ので、ここで潰す。
 * https://readouble.com/laravel/13.x/ja/mail.html#markdown-mailables
 */
trait FormatsMarkdownText
{
    /**
     * 本文の 1 行に入れる値。改行を空白にする（氏名に改行を入れて「# 見出し」などの行を作らせない）
     */
    protected static function singleLine(?string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $value ?? '');
    }

    /**
     * 表のセルに入れる値。改行を空白にし、| は GFM の表の書き方どおり \| にする
     */
    protected static function tableCell(?string $value): string
    {
        return str_replace('|', '\\|', self::singleLine($value));
    }
}
