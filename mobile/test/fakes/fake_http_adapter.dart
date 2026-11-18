// テスト用の偽の HttpClientAdapter（Step 9。issue #50 の Q2）。
// dio は「リクエストを組み立てる・インターセプタを通す・例外にする」所と、「実際に通信する」所（アダプタ）に分かれている。
// アダプタだけをこれに替えると、インターセプタ（AuthInterceptor）と ApiClient のエラーの変換は本物のまま動き、
// 通信だけが偽物になる。Laravel の Http::fake() と同じ「送信の部品だけを差し替える」考え方。
//
// mobile/ で dio を import してよいのは lib/data/services/ と、テストの test/data/services/ とこのファイルだけ（docs/01 の 7A.1）。
// https://pub.dev/documentation/dio/latest/dio/HttpClientAdapter-class.html

import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

// 1 件のリクエストに答える関数の型。RequestOptions（送り先・ヘッダなど）を受け取り、偽の応答を返す。
// FutureOr<T>: T でも Future<T> でもよい（同期でも非同期でも書ける）。
typedef FakeHandler = FutureOr<FakeResponse> Function(RequestOptions options);

// 偽の応答。body が Map / List なら JSON にし、String ならそのまま（HTML などの JSON でない応答）。
class FakeResponse {
  const FakeResponse(this.statusCode, [this.body]);

  final int statusCode;
  final Object? body;
}

class FakeHttpAdapter implements HttpClientAdapter {
  FakeHttpAdapter(this._handler);

  // 接続できない（サーバーが止まっている・圏外）ときの偽物。どのリクエストにも応答を返さず、接続の失敗にする。
  FakeHttpAdapter.unreachable()
    : _handler = ((options) => throw DioException.connectionError(
        requestOptions: options,
        reason: '接続できない（テスト）',
      ));

  final FakeHandler _handler;

  // 送られたリクエスト（ヘッダの確かめに使う）。
  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final response = await _handler(options);
    final body = response.body;
    // is: 型を確かめる（TypeScript の instanceof）。
    final isJson = body is Map || body is List;
    return ResponseBody.fromString(
      // jsonEncode: Dart の値を JSON の文字列にする（JavaScript の JSON.stringify）。
      isJson ? jsonEncode(body) : (body as String? ?? ''),
      response.statusCode,
      headers: {
        // Content-Type を JSON にすると、dio が本文を Map に戻す（jsonDecode する）。
        Headers.contentTypeHeader: [
          isJson ? Headers.jsonContentType : 'text/html; charset=utf-8',
        ],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
