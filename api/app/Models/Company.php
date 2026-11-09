<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 企業（テナント）。自分自身がテナントの単位なので BelongsToCompany は付けない。
 * code（企業コード）はワーカーのログインの識別子（docs/01 の 3.1）。秘密ではないので招待メールと管理者の画面に出してよい。
 * 作るのは creating だけで、#[Fillable] には入れない（企業を作るのは今はシーダーだけ。重なったら作り直す）。
 */
#[Fillable(['name'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** 企業コードに使う文字。紛らわしい 0 O 1 I L を除いた英大文字と数字 */
    public const CODE_CHARACTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 6;

    /**
     * 保存の前に企業コードを入れる（Rails の before_create に相当）。
     * 重なりは UNIQUE が最後に止めるが、まず既存の行を見て作り直す（同時に作ったときの重なりは QueryException になる）。
     * https://readouble.com/laravel/13.x/ja/eloquent.html#events-using-closures
     */
    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if ($company->code !== null) {
                return;
            }

            do {
                $code = self::randomCode();
            } while (static::query()->where('code', $code)->exists());

            $company->code = $code;
        });
    }

    private static function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            // 推測されにくいように暗号論的な乱数（random_int）で 1 文字ずつ選ぶ
            $code .= self::CODE_CHARACTERS[random_int(0, strlen(self::CODE_CHARACTERS) - 1)];
        }

        return $code;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
