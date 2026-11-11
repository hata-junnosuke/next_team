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

  // 401 でログイン画面に戻したときに、ログイン画面に出す理由（T10）。出したら消す（takeSignOutReason）。
  String? _signOutReason;

  // 起動したときに 1 回だけ呼ぶ: 保存したトークンがあれば、ログインした状態に戻す（T7）。
  // API は呼ばない（トークンが手元にあるかだけで決める）。トークンが失効・無効にされていたら、
  // 最初に API を呼んだときの 401 で未ログインに戻る（T10）。起動のたびに API を待つと、圏外で開けなくなる。
  //
  // Keychain の読み込みが失敗したら（まれ。バックアップから戻した直後など）、未ログインとして起動を続ける。
  // ここで例外を外に出すと main の runApp に届かず、アプリが起動画面のまま止まる。
  // 読めなかったトークンは消しておく（次のログインで書き直す）。
  Future<void> restore() async {
    try {
      final token = await _tokenStorage.readToken();
      _isLoggedIn = token != null;
    } on Exception {
      _isLoggedIn = false;
      try {
        await _tokenStorage.deleteToken();
      } on Exception {
        // 消せなくても起動は続ける（次のログインで上書きする）。
      }
    }
    notifyListeners();
  }

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

  // 前回ログインした企業コード（なければ null）。ログイン画面の入力欄に最初から入れておくために使う（T8）。
  Future<String?> lastCompanyCode() => _tokenStorage.readCompanyCode();

  // 自分の情報（GET /api/worker/me）。応答は {"data": WorkerMeResource}。
  Future<WorkerMe> fetchMe() async {
    final body = await _apiClient.get('/api/worker/me');
    return WorkerMe.fromJson(_dataOf(body));
  }

  // ログアウト（POST /api/worker/logout）。今の端末のトークンだけを API 側で消す（5.3）。
  // API の呼び出しが失敗しても（圏外・すでに失効して 401 など）、手元のトークンは必ず消してログイン画面に戻す（T11）。
  // 手元に残すと「ログアウトしたのにログインしたまま」になるため。API 側に残ったトークンは期限（30 日）で消える。
  // 企業コードは消さない（次のログインの入力欄に入れておく。7A.4）。
  Future<void> logout() async {
    try {
      await _apiClient.post('/api/worker/logout');
      // on Exception: ApiError（通信・401 など）も FormatException（応答の形）も受ける。
    } on Exception {
      // 失敗は利用者に見せない（ログアウトしたい、という目的は手元で果たせる）。
    } finally {
      // finally: 成功しても失敗しても最後に必ず動く（Ruby の ensure、JavaScript の finally）。
      // 401 のときは AuthInterceptor が理由（もう一度ログインしてください。）を入れているが、
      // 自分でログアウトしたのだから出さない。
      // 間に合う理由: 理由を読むのはログイン画面を作るとき（次の描画のフレーム）で、ここは dio の失敗が
      // 届いた直後に同じ非同期の流れで動く（フレームを待たない）ので、読まれる前に消せる。
      _signOutReason = null;
      await _signOut();
    }
  }

  // API が 401 を返したときに AuthInterceptor から呼ばれる（T10）。トークンを消して未ログインにする。
  // reason がなければ（Unauthenticated. の 401）決まりの文言にする（docs/01 の 7A.4。issue #46 の Q4）。
  // すでに未ログインなら何もしない（同時に飛んだ複数のリクエストが、続けて 401 を受け取ることがある）。
  // そのために、Keychain を消すのを待つ（await）より前に _isLoggedIn を false にする。
  // 待っている間に 2 つ目の 401 が来ても、ここで止まる。
  Future<void> expireSession(String? reason) async {
    if (!_isLoggedIn) return;
    _isLoggedIn = false;
    _signOutReason = reason ?? 'もう一度ログインしてください。';
    await _signOut();
  }

  // ログイン画面が開いたときに 1 回だけ読む。読んだら消すので、次にログイン画面を開いても同じ文言は出ない。
  String? takeSignOutReason() {
    final reason = _signOutReason;
    _signOutReason = null;
    return reason;
  }

  // ログイン・初回登録の応答 {"data": {"token", "worker"}} を読み、トークンを保存してログイン済みにする。
  Future<WorkerMe> _signIn(Map<String, dynamic>? body) async {
    // 形が違えば FormatException（契約のずれ。7A.4）。as を使わない理由は worker_me.dart。
    final (token, worker) = switch (_dataOf(body)) {
      {
        'token': final String token,
        'worker': final Map<String, dynamic> worker,
      } =>
        // ( , ) はレコード: 2 つの値を 1 つにまとめて返し、左の final (token, worker) で分けて受け取る
        // （TypeScript で [a, b] を返して const [a, b] = ... と分ける、のに近い）。
        (token, WorkerMe.fromJson(worker)),
      _ => throw const FormatException('ログインの応答に token か worker がありません'),
    };

    await _tokenStorage.writeToken(token);
    // 企業コードを覚えておく（T8）。入力した値ではなく API が返した値（大文字にそろえたもの）を保存する。
    await _tokenStorage.writeCompanyCode(worker.company.code);
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
