// ログイン画面の状態（送信の結果のエラー）と、送信の処理を持つ Provider。
// Next.js で言うと、ログインの Server Action と useActionState の state（FormState）を合わせたもの
// （web の 7.3 の FormState: {message, errors}）。
// 画面（login_screen.dart）はここを読むだけで、AuthRepository や ApiClient を直接さわらない（docs/01 の 7A.1）。
//
// Notifier: https://riverpod.dev/ja/docs/concepts2/providers
// レコード（( ) で作る名前なしの型）: https://dart.dev/language/records

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../data/services/api_error.dart';

part 'login_view_model.g.dart';

// フォームの状態の型。typedef = 型に名前を付ける（TypeScript の type FormState = {...}）。
// ({...}) は「レコード」: クラスを書かずに、名前付きの値をまとめた型を作れる（TypeScript のオブジェクト型に近い）。
// - message: 画面の上に出す文言（422 以外のエラーと、項目に結びつかないエラー）。なければ null
// - errors: 項目ごとの文言（422 の errors。company_code / email / password）。入力欄の下に出す
// 画面が ApiError（data/ の型）を知らずに済むように、ここで画面に出す形に変えておく。
typedef LoginFormState = ({String? message, Map<String, List<String>> errors});

// 何も出していない状態。
const LoginFormState _initial = (message: null, errors: {});

// 何を持つか: 直前の送信のエラー（なければ空）。
// いつ作り直されるか: keepAlive なしなので、ログイン画面を離れる（一覧に移る）と捨てられ、
// 次にログイン画面を開いたときに _initial から始まる（前のエラーが残らない）。
@riverpod
class LoginViewModel extends _$LoginViewModel {
  @override
  LoginFormState build() => _initial;

  // 送信。入力の検証はしない（空でもそのまま送り、API の 422 に任せる。CLAUDE.md の絶対ルール 1）。
  // 成功したら何もしない: AuthRepository がログイン済みにして知らせ、go_router の redirect が一覧に移す。
  Future<void> submit({
    required String companyCode,
    required String email,
    required String password,
  }) async {
    // 送り直すときは、前のエラーを消してから送る。
    state = _initial;
    try {
      // ref.read: 押した瞬間に 1 回だけ使うので watch ではなく read（購読しない）。
      await ref
          .read(authRepositoryProvider)
          .login(companyCode: companyCode, email: email, password: password);
    } on ApiError catch (e) {
      // ref.mounted: await の間に画面を離れて、この Provider が捨てられていないか。
      // 捨てられた後に state を書くと例外になるので確かめる（React の「アンマウント後に setState しない」と同じ）。
      if (!ref.mounted) return;
      state = _fromApiError(e);
    } on FormatException {
      // 応答の形が契約（6.11）と違う。アプリの不具合か API の変更なので、項目ではなく上に出す。
      if (!ref.mounted) return;
      state = (message: '応答の形が想定と違います。管理者にお問い合わせください。', errors: {});
    }
  }

  // ApiError を画面に出す形に変える。
  // 422 で項目ごとの文言があれば、項目の下に出すので上の message は出さない（同じ文言が 2 回出るのを避ける）。
  // それ以外（429・500・接続できない など）は上に出す。
  LoginFormState _fromApiError(ApiError e) {
    if (e.statusCode == 422 && e.errors.isNotEmpty) {
      return (message: null, errors: e.errors);
    }
    return (message: e.message, errors: {});
  }
}
