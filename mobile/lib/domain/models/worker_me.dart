// ログイン中のワーカー自身の情報（API の WorkerMeResource。docs/01 の 6.11）。
// ログイン・初回登録の応答と、GET /api/worker/me の応答がこの形になる。
// TypeScript で言うと、zod のスキーマ（z.object({...})）と、そこから作った型の組み合わせ。
//
// Step 6 は手書きで、形を switch のパターンで確かめて FormatException を投げていた。
// Step 7 で freezed + json_serializable の生成に移した（issue #48 の Q2）。形の違いは生成した fromJson が
// CheckedFromJsonException にし、data/services/api_client.dart の parseModel() が FormatException にそろえる。
// だから呼ぶ側（auth_repository.dart）から見た例外は、手書きのときと同じ FormatException のまま。
//
// domain/ なので Flutter も Riverpod も import しない（docs/01 の 7A.1）。freezed の書き方は job_posting.dart を参照。

import 'package:freezed_annotation/freezed_annotation.dart';

part 'worker_me.freezed.dart';
part 'worker_me.g.dart';

// ワーカーが所属する企業（WorkerMeResource の company: {id, name, code}）。
@freezed
abstract class WorkerCompany with _$WorkerCompany {
  const factory WorkerCompany({
    required int id,
    required String name,
    // 企業コード（大文字の 8 文字ほど）。次のログインの入力欄に入れておくために保存する（7A.4）。
    required String code,
  }) = _WorkerCompany;

  factory WorkerCompany.fromJson(Map<String, dynamic> json) =>
      _$WorkerCompanyFromJson(json);
}

@freezed
abstract class WorkerMe with _$WorkerMe {
  const factory WorkerMe({
    required int id,
    required String name,
    // API が小文字にそろえて保存したメール（6.11）。
    required String email,
    required WorkerCompany company,
  }) = _WorkerMe;

  factory WorkerMe.fromJson(Map<String, dynamic> json) =>
      _$WorkerMeFromJson(json);
}
