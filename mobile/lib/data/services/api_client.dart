// API を呼ぶ入口。アプリの中で dio を使ってよいのは data/services/ のこのファイルたちだけ（docs/01 の 7A.4）。
// Next.js で言うと web/lib/api.ts（直に fetch せず、必ずここを通す）に当たる。
// Rails で言うと、Faraday の接続（Faraday.new(url: ...)）を 1 か所で作って使い回すクラス。
//
// やること:
// - 接続先（API_BASE_URL）と、毎回付けるヘッダ（Accept: application/json）を決める
// - 失敗（DioException）を ApiError（api_error.dart）に変えて投げる。画面は dio の例外を見ない
// トークンを付けるのは AuthInterceptor（auth_interceptor.dart）の役目。ここでは受け取って dio に差し込むだけ。
//
// dio: https://pub.dev/packages/dio
// BaseOptions: https://pub.dev/documentation/dio/latest/dio/BaseOptions-class.html
// DioException: https://pub.dev/documentation/dio/latest/dio/DioException-class.html

import 'package:dio/dio.dart';
// CheckedFromJsonException（生成した fromJson が形の違いで投げる例外）を受けるために使う。
import 'package:json_annotation/json_annotation.dart';

import 'api_error.dart';

// 接続先。flutter run --dart-define=API_BASE_URL=http://localhost:8002 で渡した値が入る（make mobile-run が渡す）。
// String.fromEnvironment はビルドのときに値が埋め込まれる定数（Next.js の NEXT_PUBLIC_ の環境変数と同じ）。
// だから秘密は入れない（rules/common/security.md）。
// 既定値は置かない。渡し忘れたら空文字になり、main.dart が起動時に止める（docs/01 の 7A.2）。
// https://api.dart.dev/dart-core/String/String.fromEnvironment.html
const String apiBaseUrl = String.fromEnvironment('API_BASE_URL');

// サーバーに届かなかったとき（接続できない・時間切れ）の文言。API の message がないので、アプリで作る。
// 業務の判定ではなく「通信の失敗」の表示なので、アプリに置いてよい。
// API の JSON を、生成した fromJson でモデルに変える（Step 7。docs/01 の 7A.4、issue #48 の Q1）。
// リポジトリは JobPosting.fromJson(json) を直に呼ばず、parseModel(json, JobPosting.fromJson) と書く。
// 形が違うと、生成した fromJson は CheckedFromJsonException（build.yaml の checked: true）を投げる。
// それを FormatException にそろえるのがこの関数の役目。画面は「応答の形が違う」を on FormatException だけで受けられる
// （Step 6 の手書きの fromJson と同じ例外になる）。web で zod の parse が落ちたときの ZodError に当たる。
//
// <T>: 型引数（TypeScript のジェネリクス <T> と同じ）。返す型を、渡した fromJson の戻り値の型にそろえる。
// T Function(Map<String, dynamic>): 「Map を受け取って T を返す関数」の型。JobPosting.fromJson をそのまま渡せる
// （コンストラクタも関数として渡せる。Dart では「tear-off」と呼ぶ）。
// https://dart.dev/language/generics
// https://pub.dev/documentation/json_annotation/latest/json_annotation/CheckedFromJsonException-class.html
T parseModel<T>(Object? json, T Function(Map<String, dynamic> json) fromJson) {
  // is!: 「その型ではない」（TypeScript の !(x instanceof Y)）。オブジェクトでなければ、fromJson に渡す前に止める。
  if (json is! Map<String, dynamic>) {
    throw FormatException('$T の応答が JSON のオブジェクトではありません');
  }
  try {
    return fromJson(json);
  } on CheckedFromJsonException catch (e) {
    // 例外の文言にはクラス名とキーだけを入れ、値（氏名・メールなど）は入れない（個人情報を例外やログに残さない）。
    // e.message は元の例外の文言で、値が入ることがある（DateTime.parse の失敗など）ので使わない。
    throw FormatException('${e.className ?? T} の形が違います（キー: ${e.key}）');
  }
}

const String _networkErrorMessage = 'サーバーに接続できません。通信環境を確かめて、もう一度お試しください。';

class ApiClient {
  // コンストラクタの : の後ろ（初期化リスト）で、フィールド _dio を作る。
  // _ で始まる名前はこのファイルの外から見えない（Dart の private。TypeScript の private / # に近い）。
  // interceptors: 毎回はさむ処理の一覧（AuthInterceptor など）。外から渡すので、ApiClient はトークンを知らない。
  // const [] は「渡されなかったときの空の一覧」。
  ApiClient({
    required String baseUrl,
    List<Interceptor> interceptors = const [],
  }) : _dio = Dio(
        BaseOptions(
          baseUrl: baseUrl,
          // Accept: application/json を付けないと、Laravel は 401 や 422 で JSON ではなく
          // リダイレクト（ログイン画面へ）を返そうとする。web の lib/api.ts も同じヘッダを付けている。
          headers: {'Accept': 'application/json'},
          // 待つ時間の上限。超えたら DioException（connectionTimeout / receiveTimeout）になる。
          // Duration は時間の長さを表す型（JavaScript ではミリ秒の数で渡すところ）。
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 15),
          // リダイレクト（3xx）を追わない。API は JSON だけを返し、3xx を返す契約はない（docs/01 の 6.1）。
          // 追うと、行き先によっては Authorization ヘッダの付いたリクエストが別の所へ送られうる。
          // 3xx は失敗として ApiError（状態コードつきの文言）になる。
          followRedirects: false,
        ),
      ) {
    // 初期化リストの後の { } はコンストラクタの本体。_dio ができてから動く。
    _dio.interceptors.addAll(interceptors);
  }

  final Dio _dio;

  // GET。成功したら本文の JSON（{"data": …} の形のオブジェクト）を返す。
  // Future<T> は「あとで T が届く」値（JavaScript の Promise<T>）。async / await も同じ使い方。
  // Map<String, dynamic> は JSON のオブジェクト（TypeScript の Record<string, any>）。
  // dynamic は「型を検査しない」（TS の any）。ここでは型にせず、リポジトリがモデルの fromJson で確かめて型に変える（7A.4）。
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
    // 状態コードは JSON の外にあるので、fromJson で作ったあと copyWith で足す。
    // copyWith: 一部の項目だけを替えた新しい値を作る（freezed が生成する。JavaScript の {...error, statusCode}）。
    // 形が違えば parseModel が FormatException を投げ、それが ApiError の代わりに呼んだ側へ届く（契約のずれ。7A.4）。
    if (data is Map<String, dynamic>) {
      return parseModel(
        data,
        ApiError.fromJson,
      ).copyWith(statusCode: statusCode);
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
