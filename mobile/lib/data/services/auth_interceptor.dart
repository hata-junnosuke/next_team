// すべてのリクエストに「Authorization: Bearer <トークン>」を付け、401 が返ったらログインの状態を切るインターセプタ
// （docs/01 の 5.3・7A.4）。
// インターセプタ = dio がリクエストを送る前・応答を受け取った後に、毎回はさむ処理。
// Next.js で言うと、lib/api.ts の中で毎回 Cookie をヘッダに写している所。
// Rails で言うと Faraday のミドルウェア（conn.request :authorization, 'Bearer', token）。
// 画面やリポジトリはトークンのことを知らずに済み、Authorization を書くのはこのファイルだけになる。
//
// Interceptor: https://pub.dev/documentation/dio/latest/dio/Interceptor-class.html
// 認証つきのリクエストの作例: https://docs.flutter.dev/cookbook/networking/authenticated-requests

import 'package:dio/dio.dart';

import 'token_storage.dart';

// 401 を受け取ったときに呼ぶ関数の型。typedef = 型に名前を付ける（TypeScript の type X = (reason) => Promise<void>）。
// reason: ログイン画面に出す理由（API の message）。理由を出さない 401 なら null（docs/01 の 7A.4。issue #46 の Q4）。
typedef UnauthorizedCallback = Future<void> Function(String? reason);

// extends Interceptor: dio のインターセプタの型を継承し、必要なメソッド（onRequest と onError）だけを上書きする。
class AuthInterceptor extends Interceptor {
  // onUnauthorized: 401 のときに呼ぶ関数を外から受け取る。
  // AuthRepository を直接持たないのは、AuthRepository が ApiClient を持ち、ApiClient がこのインターセプタを持つので、
  // 直接持つと「作るのに互いが要る」輪になるため。関数で受け取れば、呼ぶときに初めて相手を探せばよい
  // （config/dependencies.dart で ref.read を包んだ関数を渡す）。
  AuthInterceptor({
    required this._tokenStorage,
    required this._onUnauthorized,
  });

  final TokenStorage _tokenStorage;
  final UnauthorizedCallback _onUnauthorized;

  // このリクエストに付けたトークンを、応答が返るまで覚えておくための名前（options.extra のキー）。
  // extra = dio のリクエストに付けて持ち回れる「ヘッダにならないメモ」。サーバーには送られない。
  static const String _sentTokenKey = 'auth_interceptor.sent_token';

  // 送る前に毎回呼ばれる。options が送ろうとしているリクエスト（URL・ヘッダ・本文）。
  // handler.next(options) を呼ぶと、次（実際の送信）に進む。呼び忘れると、リクエストが止まったままになる。
  // async にして Keychain を読み終えてから next を呼ぶ（Express の middleware で await してから next() するのと同じ）。
  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _tokenStorage.readToken();
    // トークンがあり、送り先が API（API_BASE_URL と同じ scheme・host・port）のときだけ付ける。
    // ログインと初回登録の前はトークンがないので、付けずに送る。
    // dio は path に http:// から始まる URL を渡されると baseUrl を使わずにそこへ送るので、確かめないと
    // 別のサイトにトークンを渡してしまう（Step 7 でページ送りの links.next の URL を使うときなど）。
    // web で Cookie が自分のドメインにだけ送られるのを、ここでは自分で守っている。
    if (token != null && _isApiOrigin(options)) {
      options.headers['Authorization'] = 'Bearer $token';
      options.extra[_sentTokenKey] = token;
    }
    handler.next(options);
  }

  // 失敗の応答を受け取るたびに呼ばれる（T10）。
  // 401 = トークンが失効した・ログアウト済み・ワーカーが無効にされた（docs/01 の 5.3）。
  // ここでは画面を移さない。ログインの状態を未ログインにするだけで、行き先は go_router の redirect が決める（7A.4）。
  // React で言うと、fetch のラッパーで 401 を見たら store の user を null にし、画面の切り替えはルーターに任せるのと同じ。
  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (err.response?.statusCode == 401 && await _sentWithCurrentToken(err)) {
      // 未ログインにする処理が失敗しても（Keychain に書けない、など。まれ）、元の 401 はそのまま流す。
      // ここで例外を外に出すと、dio が 401 の代わりに「応答なし」の失敗を流し、画面の文言がずれる。
      try {
        await _onUnauthorized(_reasonOf(err.response?.data));
      } on Exception {
        // 次の API の 401 でもう一度試す。
      }
    }
    // handler.next(err): エラーをそのまま次へ流す（ApiClient が ApiError に変えて、呼んだ側に投げる）。
    handler.next(err);
  }

  // 送り先が API と同じ scheme・host・port か。options.uri は baseUrl と path を合わせた、実際に送る URL。
  bool _isApiOrigin(RequestOptions options) {
    final api = Uri.parse(options.baseUrl);
    final target = options.uri;
    return target.scheme == api.scheme &&
        target.host == api.host &&
        target.port == api.port;
  }

  // 401 を返したリクエストが、今保存しているトークンで送ったものか。
  // 古いトークンで送ったリクエストの 401 が遅れて届いたとき（その間にログインし直していた）に、
  // 新しいログインまで切ってしまわないようにする。トークンを付けずに送ったリクエストの 401 も無視する。
  Future<bool> _sentWithCurrentToken(DioException err) async {
    final sent = err.requestOptions.extra[_sentTokenKey];
    if (sent == null) return false;
    return sent == await _tokenStorage.readToken();
  }

  // ログイン画面に出す理由（issue #46 の Q4）。
  // 応答に errors があれば message（無効にされたワーカーの「このアカウントは現在利用できません。…」）。
  // errors がない 401（{"message": "Unauthenticated."}。英語）は null を返し、ログイン画面が決まりの文言を出す。
  String? _reasonOf(Object? data) {
    return switch (data) {
      // Map のパターン: errors というキーがあり、message が String のときだけ当たる。
      {'errors': _, 'message': final String message} => message,
      _ => null,
    };
  }
}
