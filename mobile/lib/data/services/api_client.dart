// API を呼ぶ入口。アプリの中で dio を使ってよいのは data/services/ のこのファイルたちだけ（docs/01 の 7A.4）。
// Next.js で言うと web/lib/api.ts（直に fetch せず、必ずここを通す）に当たる。
// Rails で言うと、Faraday の接続（Faraday.new(url: ...)）を 1 か所で作って使い回すクラス。
//
// やること:
// - 接続先（API_BASE_URL）と、毎回付けるヘッダ（Accept: application/json）を決める
// - 失敗（DioException）を ApiError（api_error.dart）に変えて投げる。画面は dio の例外を見ない
// トークンを付けるのは Step 6 の後のタスクで足す AuthInterceptor の役目（ここには書かない）。
//
// dio: https://pub.dev/packages/dio
// BaseOptions: https://pub.dev/documentation/dio/latest/dio/BaseOptions-class.html
// DioException: https://pub.dev/documentation/dio/latest/dio/DioException-class.html

import 'package:dio/dio.dart';

import 'api_error.dart';

// 接続先。flutter run --dart-define=API_BASE_URL=http://localhost:8002 で渡した値が入る（make mobile-run が渡す）。
// String.fromEnvironment はビルドのときに値が埋め込まれる定数（Next.js の NEXT_PUBLIC_ の環境変数と同じ）。
// だから秘密は入れない（rules/common/security.md）。
// 既定値は置かない。渡し忘れたら空文字になり、main.dart が起動時に止める（docs/01 の 7A.2）。
// https://api.dart.dev/dart-core/String/String.fromEnvironment.html
const String apiBaseUrl = String.fromEnvironment('API_BASE_URL');

// サーバーに届かなかったとき（接続できない・時間切れ）の文言。API の message がないので、アプリで作る。
// 業務の判定ではなく「通信の失敗」の表示なので、アプリに置いてよい。
const String _networkErrorMessage = 'サーバーに接続できません。通信環境を確かめて、もう一度お試しください。';

class ApiClient {
  // コンストラクタの : の後ろ（初期化リスト）で、フィールド _dio を作る。
  // _ で始まる名前はこのファイルの外から見えない（Dart の private。TypeScript の private / # に近い）。
  ApiClient({required String baseUrl})
    : _dio = Dio(
        BaseOptions(
          baseUrl: baseUrl,
          // Accept: application/json を付けないと、Laravel は 401 や 422 で JSON ではなく
          // リダイレクト（ログイン画面へ）を返そうとする。web の lib/api.ts も同じヘッダを付けている。
          headers: {'Accept': 'application/json'},
          // 待つ時間の上限。超えたら DioException（connectionTimeout / receiveTimeout）になる。
          // Duration は時間の長さを表す型（JavaScript ではミリ秒の数で渡すところ）。
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 15),
        ),
      );

  final Dio _dio;

  // GET。成功したら本文の JSON（{"data": …} の形のオブジェクト）を返す。
  // Future<T> は「あとで T が届く」値（JavaScript の Promise<T>）。async / await も同じ使い方。
  // Map<String, dynamic> は JSON のオブジェクト（TypeScript の Record<string, unknown>）。
  // dynamic は「型を決めない」。ここでは型にせず、リポジトリがモデルの fromJson で型に変える（7A.4）。
  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    final body = await _send(
      () => _dio.get<Object?>(path, queryParameters: query),
    );
    return _asObject(body, path);
  }

  // POST。204（本文なし。ログアウトなど）のときは null を返すので、戻り値は Map? にしている。
  Future<Map<String, dynamic>?> post(String path, {Object? data}) async {
    final body = await _send(() => _dio.post<Object?>(path, data: data));
    // 本文がなければ null。あれば JSON のオブジェクトであることを確かめて返す。
    return body == null || body == '' ? null : _asObject(body, path);
  }

  // dio の呼び出しを 1 つ受け取って実行し、失敗を ApiError に変える。
  // 引数の型 Future<Response<Object?>> Function() は「呼ぶと Future を返す関数」
  // （TypeScript の () => Promise<Response>）。GET と POST の違いだけを外から渡す。
  Future<Object?> _send(Future<Response<Object?>> Function() request) async {
    try {
      final response = await request();
      return response.data;
      // on DioException catch (e): DioException の種類のときだけ受ける（Ruby の rescue Faraday::Error => e）。
    } on DioException catch (e) {
      throw _toApiError(e);
    }
  }

  // DioException を ApiError に変える。
  ApiError _toApiError(DioException e) {
    final response = e.response;

    // 応答が返ってこなかった（サーバーが止まっている・時間切れ・圏外など）。
    // statusCode は null にして、画面が「届かなかった」と見分けられるようにする。
    if (response == null) {
      return const ApiError(statusCode: null, message: _networkErrorMessage);
    }

    final statusCode = response.statusCode ?? 0;
    final data = response.data;
    // 6.1 の形 {message, errors} なら、それを使う（422 の項目ごとの文言もここに入る）。
    if (data is Map<String, dynamic>) {
      return ApiError.fromJson(statusCode, data);
    }
    // JSON でない応答（Laravel が落ちて HTML を返した、など）。中身は画面に出さない。
    return ApiError(
      statusCode: statusCode,
      message: 'サーバーでエラーが起きました（状態コード $statusCode）。時間をおいてもう一度お試しください。',
    );
  }

  // 成功の本文が JSON のオブジェクトであることを確かめる。
  // 違っていたら契約（docs/01 の 6.11）とのずれなので、黙って使わずに例外にする（7A.4）。
  Map<String, dynamic> _asObject(Object? body, String path) {
    if (body is Map<String, dynamic>) {
      return body;
    }
    throw FormatException('$path の応答が JSON のオブジェクトではありません');
  }
}
