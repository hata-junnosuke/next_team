// テスト用の偽の ApiClient（Step 9。issue #50 の Q1: パッケージを足さず手書きにする）。
// Laravel で言うと Http::fake([...]) に当たる。送ったリクエストを記録し（Http::assertSent）、用意した応答を返す。
// React で言うと、jest.fn() で fetch を差し替えるのに近いが、ここではモックの道具を使わずクラスを 1 つ書く。
//
// implements ApiClient: ApiClient の「形」（公開のメソッド get と post）だけを受け継ぎ、中身は自分で書く。
// extends（継承）と違い、本物の中身（dio）は 1 行も動かない。だから本物のネットワークに触れない。
// リポジトリは ApiClient の型しか知らないので、本物の代わりにこれを渡せる（リポジトリの差し替えの復習。Step 6〜7）。
// https://dart.dev/language/classes#implicit-interfaces

import 'dart:async';

import 'package:worker_app/data/services/api_client.dart';

// 記録した 1 件のリクエスト。
// レコード型（Dart 3）でも書けるが、テストで名前を読みやすくするため小さなクラスにする。
class SentRequest {
  SentRequest(this.method, this.path, {this.query, this.data});

  final String method;
  final String path;
  final Map<String, dynamic>? query;
  final Object? data;

  // 失敗したときのメッセージで中身が読めるように（expect の差分に出る）。
  @override
  String toString() => '$method $path query=$query data=$data';
}

class FakeApiClient implements ApiClient {
  // 'GET /api/worker/locations' のような「メソッド パス」をキーにした応答。
  // 値が Exception（ApiError・FormatException など）なら、返さずに投げる。
  // Object?: 何でも入る型（null も）。TypeScript の unknown に近い。
  final Map<String, Object?> responses = {};

  // 応答を止める「門」。Completer は「あとで自分で完了させる Future」（JavaScript で resolve を外に持ち出した Promise）。
  // 門があると、どのリクエストも complete() されるまで返らない。「送信中」の場面を作るのに使う。
  // https://api.dart.dev/dart-async/Completer-class.html
  Completer<void>? gate;

  // 送られたリクエストを順に記録する（Http::assertSent の代わりに、テストで中身を見る）。
  final List<SentRequest> sent = [];

  // 応答を用意する。テストでは fake.respond('GET', '/api/...', {...}) と書く。
  void respond(String method, String path, Object? response) {
    responses['$method $path'] = response;
  }

  @override
  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    sent.add(SentRequest('GET', path, query: query));
    // ?.: 門がなければ（null なら）待たない。
    await gate?.future;
    // as: 型を言い切る（TypeScript の as）。GET の応答は必ず Map なので、用意し忘れはテストの書き間違い。
    return _answer('GET', path) as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>?> post(String path, {Object? data}) async {
    sent.add(SentRequest('POST', path, data: data));
    await gate?.future;
    return _answer('POST', path) as Map<String, dynamic>?;
  }

  Object? _answer(String method, String path) {
    final key = '$method $path';
    // containsKey: キーがあるか（Ruby の Hash#key?）。null を「204 の空の応答」として用意できるように、値ではなくキーで見る。
    if (!responses.containsKey(key)) {
      // StateError: 「テストの準備が足りない」ことをはっきり出す（黙って null を返すと原因が分かりにくい）。
      throw StateError('FakeApiClient: $key の応答を用意していません');
    }
    final response = responses[key];
    if (response is Exception) throw response;
    return response;
  }
}
