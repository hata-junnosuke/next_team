<?php

namespace App\Exceptions;

use DomainException as BaseDomainException;

/**
 * 業務ルール違反（定員超過・遷移不可など）。メッセージは日本語で、画面にそのまま出る。
 * bootstrap/app.php で 422 {"message": "...", "errors": {...}} に変換する。
 * 入力欄に紐づけたいときは field を渡すと errors.<field> に入る。
 */
class DomainException extends BaseDomainException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
