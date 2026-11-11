// API が失敗したときに投げる例外。docs/01 の 6.1 のエラーの形 {message, errors} と、状態コードを持つ。
// Next.js で言うと、lib/api.ts が 422 などを受け取ったときに投げる ApiError クラスに当たる。
// Rails で言うと、Faraday の例外（Faraday::ClientError）を、アプリの言葉に包み直したもの。
//
// 画面は dio の例外（DioException）を見ずに、これだけを見る。
// dio を知っているのは data/services/ だけにしたいので（docs/01 の 7A.4）、ApiClient が変換して投げる。
//
// 例外のクラスを作る: https://dart.dev/language/error-handling

// implements Exception: 「これは例外として投げてよいもの」という印（Java の implements と同じ）。
// Dart は何でも throw できるが、Exception を実装しておくと、catch (e) で受けた側が種類で見分けやすい。
class ApiError implements Exception {
  // コンストラクタ。{} の中は名前付き引数（呼ぶ側が ApiError(message: '…') のように名前で渡す）。
  // required = 省略できない名前付き引数（TypeScript で ? を付けないプロパティ）。
  // this.message = 受け取った値を、そのまま同じ名前のフィールドに入れる短い書き方。
  // const: 中身が変わらないので、コンパイル時に作れる（同じ値なら使い回される）。
  const ApiError({
    required this.statusCode,
    required this.message,
    // = const {} は「渡されなかったときの値」（空のマップ）。
    this.errors = const {},
  });

  // 6.1 のエラーの形 {message, errors} の JSON から作る。
  // factory: 新しく作るとは限らないコンストラクタ（ここでは JSON を調べてから作るために使う）。
  // Step 7 からは freezed と json_serializable で生成するが、Step 6 は手書き（docs/01 の 7A.4）。
  //
  // 形が合わなければ FormatException を投げる（web で zod の parse が落ちるのと同じ。7A.4）。
  // 黙って空の文言にすると、契約のずれに気づけない。
  factory ApiError.fromJson(int statusCode, Map<String, dynamic> json) {
    // switch のパターンマッチ: json の形が「message が String」なら、その値を message という変数に入れる。
    // TypeScript で言うと、typeof json.message === 'string' で絞り込むのを 1 行で書く書き方。
    // https://dart.dev/language/patterns
    final message = switch (json['message']) {
      final String m => m,
      _ => throw FormatException('API のエラーに message がありません: $json'),
    };

    // errors は省略されることがある（401 の「Unauthenticated.」など。6.11）。
    // ある場合は {"email": ["…"]} の形（項目名 → 文言の配列）。
    final errors = <String, List<String>>{};
    final raw = json['errors'];
    // is: 型を確かめる（TypeScript の instanceof / typeof）。確かめた後の raw は Map として使える。
    if (raw is Map<String, dynamic>) {
      // for (final MapEntry(:key, :value) in ...) は、マップの 1 組をキーと値に分けて取り出す書き方。
      for (final MapEntry(:key, :value) in raw.entries) {
        if (value is! List) {
          throw FormatException('API のエラーの errors.$key が配列ではありません: $json');
        }
        // List<dynamic> を List<String> にそろえる。文字列でないものが混ざっていたら契約のずれ。
        errors[key] = [
          for (final item in value)
            if (item is String)
              item
            else
              throw FormatException('API のエラーの errors.$key に文字列でないものがあります: $json'),
        ];
      }
    } else if (raw != null) {
      throw FormatException('API のエラーの errors がオブジェクトではありません: $json');
    }

    return ApiError(statusCode: statusCode, message: message, errors: errors);
  }

  // 状態コード（401 / 404 / 422 / 429 / 500 など）。
  // int? は「int か null」。null は「サーバーに届かなかった」（接続できない・時間切れ）を表す。
  final int? statusCode;

  // 画面にそのまま出せる日本語の文言（API の message。接続できないときは ApiClient が作った文言）。
  final String message;

  // 422 の項目ごとの文言（{"email": ["…"]}）。入力欄の下に出す（docs/01 の 7A.4）。
  // 他の状態コードでは空のことが多い。
  final Map<String, List<String>> errors;

  // その項目の最初の文言（なければ null）。入力欄の下に 1 行だけ出すために使う。
  // ?. は「左が null なら null を返し、そうでなければ続ける」（JavaScript の ?. と同じ）。
  String? firstErrorOf(String field) => errors[field]?.firstOrNull;

  // print や例外のログに出たときの見え方。トークンなどの秘密はここに入らない（文言と状態コードだけ）。
  @override
  String toString() => 'ApiError($statusCode): $message';
}
