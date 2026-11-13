// API が失敗したときに投げる例外。docs/01 の 6.1 のエラーの形 {message, errors} と、状態コードを持つ。
// Next.js で言うと、lib/api.ts が 422 などを受け取ったときに投げる ApiError クラスに当たる。
// Rails で言うと、Faraday の例外（Faraday::ClientError）を、アプリの言葉に包み直したもの。
//
// 画面は dio の例外（DioException）を見ずに、これだけを見る。
// dio を知っているのは data/services/ だけにしたいので（docs/01 の 7A.4）、ApiClient が変換して投げる。
//
// Step 6 は手書きだった。Step 7 で freezed + json_serializable の生成に移した（issue #48 の Q2）。
// 公開の形（statusCode・message・errors・firstErrorOf()・toString()）は変えていないので、使う側（ui/）は変わらない。
// モデル（domain/models/）ではなく API の失敗の形なので、data/services/ に置いたまま（7A.1）。
//
// 例外のクラスを作る: https://dart.dev/language/error-handling
// freezed にメソッドを足す（private コンストラクタ）: https://pub.dev/packages/freezed （「Adding getters and methods to our models」）

import 'package:freezed_annotation/freezed_annotation.dart';

part 'api_error.freezed.dart';
part 'api_error.g.dart';

// implements Exception: 「これは例外として投げてよいもの」という印（Java の implements と同じ）。
// Dart は何でも throw できるが、Exception を実装しておくと、on Exception で受けられる（Error = バグとは分けられる）。
//
// @Freezed(toStringOverride: false): freezed は既定で toString（ApiError(statusCode: 422, message: …)）を作る。
// 自分で toString を書けば freezed は作らないが、「message だけを返す toString を使う」という意図をはっきりさせるため明示する。
@Freezed(toStringOverride: false)
abstract class ApiError with _$ApiError implements Exception {
  // const ApiError._(): 中身のない private のコンストラクタ。freezed のクラスに自分のメソッド（firstErrorOf・toString）を
  // 足すときに必要になる決まりごと（生成された _ApiError が、これを呼んで親のメソッドを受け継ぐ）。
  const ApiError._();

  const factory ApiError({
    // 状態コード（401 / 404 / 422 / 429 / 500 など）。null は「サーバーに届かなかった」（接続できない・時間切れ）。
    // JSON の中にはないので、fromJson では読まない（includeFromJson: false）。ApiClient が copyWith で入れる。
    @JsonKey(includeFromJson: false) int? statusCode,
    // 画面にそのまま出せる日本語の文言（API の message。接続できないときは ApiClient が作った文言）。
    required String message,
    // 422 の項目ごとの文言（{"email": ["…"]}）。入力欄の下に出す（docs/01 の 7A.4）。
    // @Default: 省略されたとき（JSON にキーがない・null。401 の「Unauthenticated.」など）の値。空のマップにする。
    // 形が違う（配列でない・文字列でないものが混ざる）ときは、生成した fromJson が例外にする（parseModel で FormatException）。
    @Default(<String, List<String>>{}) Map<String, List<String>> errors,
  }) = _ApiError;

  // 6.1 のエラーの形 {message, errors} の JSON から作る（状態コードは含まない）。
  // ApiClient は parseModel(json, ApiError.fromJson).copyWith(statusCode: …) と呼ぶ。
  factory ApiError.fromJson(Map<String, dynamic> json) =>
      _$ApiErrorFromJson(json);

  // その項目の最初の文言（なければ null）。入力欄の下に 1 行だけ出すために使う。
  // ?. は「左が null なら null を返し、そうでなければ続ける」（JavaScript の ?. と同じ）。
  String? firstErrorOf(String field) => errors[field]?.firstOrNull;

  // 文字列にしたときの見え方。画面で '$error' と書くと、この日本語の文言がそのまま出る
  // （画面が ApiError の型を知らなくても、利用者向けの文言を出せる）。トークンなどの秘密は入らない。
  @override
  String toString() => message;
}
