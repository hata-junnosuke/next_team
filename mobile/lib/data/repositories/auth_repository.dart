// ワーカーのログイン・初回登録・ログアウトと、ログインの状態を持つリポジトリ。
// docs/01 の 7A.1（issue #46 の Q1）: ログインの状態はここが持ち、go_router が refreshListenable で聞く。
// Flutter 公式のサンプル（compass_app）の AuthRepository と同じ形。
//
// Next.js で言うと、ログインの Server Action（lib/api.ts を呼んで Cookie を受け取る所）と、
// 「ログイン中かどうか」を持つ Context をまとめたもの。
// Rails で言うと、SessionsController の create / destroy の中身と current_user? を、画面から切り離したもの。
//
// data/ なので Riverpod は import しない（docs/01 の 7A.1）。Riverpod の Provider は config/dependencies.dart が作る。
//
// ChangeNotifier: https://api.flutter.dev/flutter/foundation/ChangeNotifier-class.html
// 公式の構成（リポジトリが状態を持つ）: https://docs.flutter.dev/app-architecture/case-study/data-layer

// ChangeNotifier は Flutter の foundation（Widget を含まない土台の部分）にある。
// data/ は Flutter を使ってよい（Riverpod は使わない）。
import 'package:flutter/foundation.dart';

import '../../domain/models/worker_me.dart';
import '../services/api_client.dart';
import '../services/device_name.dart';
import '../services/token_storage.dart';

// extends ChangeNotifier: 「変わったよ」と知らせる仕組み（notifyListeners）を持つクラスを継承する。
// React で言うと、外部ストアの subscribe / emit（useSyncExternalStore で読むような store）に当たる。
// go_router はこれを refreshListenable で聞き、知らせが来たら redirect をやり直す（routing/router.dart。T5）。
class AuthRepository extends ChangeNotifier {
  // required this._apiClient: 名前付き引数を、そのまま _ 付きのフィールドに入れる書き方（Dart 3.12 から）。
  // 呼ぶ側は _ を外した名前で渡す: AuthRepository(apiClient: …, tokenStorage: …)。
  // https://dart.dev/language/constructors
  AuthRepository({
    required this._apiClient,
    required this._tokenStorage,
  });

  final ApiClient _apiClient;
  final TokenStorage _tokenStorage;

  // ログインしているか。トークンを保存したら true、消したら false。
  // 外からは読むだけにしたいので、フィールドは _ で隠し、get で読ませる（TypeScript の private ＋ getter）。
  bool _isLoggedIn = false;
  bool get isLoggedIn => _isLoggedIn;

  // ログイン（POST /api/worker/login。docs/01 の 6.11 と 5.3）。
  // 成功したらトークンを保存し、ログイン済みにして知らせる。自分の情報（WorkerMe）を返す。
  // 失敗したら ApiClient が ApiError を投げるので、ここでは受けずに、そのまま呼んだ側（view_models）に渡す。
  // 入力の検証はしない（空でも送る）。検証は API の 422 に任せる（CLAUDE.md の絶対ルール 1）。
  Future<WorkerMe> login({
    required String companyCode,
    required String email,
    required String password,
  }) async {
    final body = await _apiClient.post(
      '/api/worker/login',
      // キーは 6.11 と同じ snake_case。
      data: {
        'company_code': companyCode,
        'email': email,
        'password': password,
        'device_name': deviceName(),
      },
    );
    return _signIn(body);
  }

  // 初回登録（POST /api/worker/register）。招待コードでパスワードを決め、そのままログインした状態になる。
  Future<WorkerMe> register({
    required String email,
    required String code,
    required String password,
    required String passwordConfirmation,
  }) async {
    final body = await _apiClient.post(
      '/api/worker/register',
      data: {
        'email': email,
        'code': code,
        'password': password,
        'password_confirmation': passwordConfirmation,
        'device_name': deviceName(),
      },
    );
    return _signIn(body);
  }

  // 自分の情報（GET /api/worker/me）。応答は {"data": WorkerMeResource}。
  Future<WorkerMe> fetchMe() async {
    final body = await _apiClient.get('/api/worker/me');
    return WorkerMe.fromJson(_dataOf(body));
  }

  // ログアウト（POST /api/worker/logout）。今の端末のトークンだけを API 側で消す（5.3）。
  Future<void> logout() async {
    await _apiClient.post('/api/worker/logout');
    await _signOut();
  }

  // ログイン・初回登録の応答 {"data": {"token", "worker"}} を読み、トークンを保存してログイン済みにする。
  Future<WorkerMe> _signIn(Map<String, dynamic>? body) async {
    final data = _dataOf(body);
    // 形が違えば as で例外になる（契約のずれ。7A.4）。
    final token = data['token'] as String;
    final worker = WorkerMe.fromJson(data['worker'] as Map<String, dynamic>);

    await _tokenStorage.writeToken(token);
    _isLoggedIn = true;
    // 聞いている人（go_router）に「変わった」と知らせる。redirect がやり直され、ログイン画面から一覧に移る。
    notifyListeners();
    return worker;
  }

  // トークンを消して未ログインにする。
  Future<void> _signOut() async {
    await _tokenStorage.deleteToken();
    _isLoggedIn = false;
    notifyListeners();
  }

  // {"data": {...}} の data を取り出す。なければ契約のずれ。
  Map<String, dynamic> _dataOf(Map<String, dynamic>? body) {
    // switch のパターン: body が null でなく、data が Map なら、その中身を返す。
    return switch (body) {
      {'data': final Map<String, dynamic> data} => data,
      _ => throw const FormatException('応答に data がありません'),
    };
  }
}
