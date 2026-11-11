// すべてのリクエストに「Authorization: Bearer <トークン>」を付けるインターセプタ（docs/01 の 5.3・7A.4）。
// インターセプタ = dio がリクエストを送る前・応答を受け取った後に、毎回はさむ処理。
// Next.js で言うと、lib/api.ts の中で毎回 Cookie をヘッダに写している所。
// Rails で言うと Faraday のミドルウェア（conn.request :authorization, 'Bearer', token）。
// 画面やリポジトリはトークンのことを知らずに済み、Authorization を書くのはこのファイルだけになる。
//
// Interceptor: https://pub.dev/documentation/dio/latest/dio/Interceptor-class.html
// 認証つきのリクエストの作例: https://docs.flutter.dev/cookbook/networking/authenticated-requests

import 'package:dio/dio.dart';

import 'token_storage.dart';

// extends Interceptor: dio のインターセプタの型を継承し、必要なメソッド（onRequest）だけを上書きする。
class AuthInterceptor extends Interceptor {
  AuthInterceptor({required this._tokenStorage});

  final TokenStorage _tokenStorage;

  // 送る前に毎回呼ばれる。options が送ろうとしているリクエスト（URL・ヘッダ・本文）。
  // handler.next(options) を呼ぶと、次（実際の送信）に進む。呼び忘れると、リクエストが止まったままになる。
  // async にして Keychain を読み終えてから next を呼ぶ（Express の middleware で await してから next() するのと同じ）。
  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _tokenStorage.readToken();
    // トークンがあれば付ける。ログインと初回登録の前はないので、付けずに送る。
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}
