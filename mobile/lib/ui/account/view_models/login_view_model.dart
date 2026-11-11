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
// - isSubmitting: 送っている途中か（T13。true の間はボタンを押せなくし、くるくるを出す。useFormStatus の pending）
// - message: 画面の上に出す文言（422 以外のエラーと、項目に結びつかないエラー）。なければ null
// - errors: 項目ごとの文言（422 の errors。company_code / email / password）。入力欄の下に出す
// 画面が ApiError（data/ の型）を知らずに済むように、ここで画面に出す形に変えておく。
typedef LoginFormState = ({
  bool isSubmitting,
  String? message,
  Map<String, List<String>> errors,
});

// 何も出していない状態。
const LoginFormState _initial = (isSubmitting: false, message: null, errors: {});

// 前回ログインした企業コード（T8）。ログイン画面を開いたときに 1 回だけ読み、入力欄に入れておく。
// Future を返す関数の Provider なので、読む側には AsyncValue<String?>（読み込み中 / エラー / 値）として届く。
// 何を持つか: Keychain から読んだ企業コード（なければ null）。
// いつ作り直されるか: keepAlive なしなので、ログイン画面を離れると捨てられ、次に開いたときに読み直す
// （ログアウトしてログイン画面に戻ったときに、直前のログインで保存した値が入る）。
@riverpod
Future<String?> lastCompanyCode(Ref ref) =>
    ref.watch(authRepositoryProvider).lastCompanyCode();

// 何を持つか: 直前の送信のエラー（なければ空）。
// いつ作り直されるか: keepAlive なしなので、ログイン画面を離れる（一覧に移る）と捨てられ、
// 次にログイン画面を開いたときに作り直される（前のエラーが残らない）。
@riverpod
class LoginViewModel extends _$LoginViewModel {
  // 最初の値。401 でログイン画面に戻されたときは、その理由を上の帯に出す（T10。docs/01 の 7A.4）。
  // 例: 管理者に無効にされた →「このアカウントは現在利用できません。管理者にお問い合わせください。」
  // takeSignOutReason は読んだら消すので、この画面を開き直したときには出ない。
  @override
  LoginFormState build() {
    final reason = ref.read(authRepositoryProvider).takeSignOutReason();
    return (isSubmitting: false, message: reason, errors: const {});
  }

  // 送信。入力の検証はしない（空でもそのまま送り、API の 422 に任せる。CLAUDE.md の絶対ルール 1）。
  // 成功したら何もしない: AuthRepository がログイン済みにして知らせ、go_router の redirect が一覧に移す。
  Future<void> submit({
    required String companyCode,
    required String email,
    required String password,
  }) async {
    // 送っている途中なら何もしない（T13）。ボタンは押せなくしているが、キーボードの「完了」や連打でも 2 回送らない。
    if (state.isSubmitting) return;
    // 送り直すときは、前のエラーを消してから送る。
    state = (isSubmitting: true, message: null, errors: const {});

    // 送った結果（次の state）。成功なら _initial、失敗ならエラーを入れたもの。
    var next = _initial;
    try {
      // ref.read: 押した瞬間に 1 回だけ使うので watch ではなく read（購読しない）。
      await ref
          .read(authRepositoryProvider)
          .login(companyCode: companyCode, email: email, password: password);
    } on ApiError catch (e) {
      next = _fromApiError(e);
    } on FormatException {
      // 応答の形が契約（6.11）と違う。アプリの不具合か API の変更なので、項目ではなく上に出す。
      next = _formatError;
    } finally {
      // finally: どう終わっても（思わぬ例外でも）送信中を解く。解かないとボタンが押せないままになる。
      // ref.mounted: await の間に画面を離れて（ログインに成功して一覧に移って）、この Provider が捨てられていないか。
      // 捨てられた後に state を書くと例外になるので確かめる（React の「アンマウント後に setState しない」と同じ）。
      if (ref.mounted) state = next;
    }
  }

  // ApiError を画面に出す形に変える。
  // 422 で項目ごとの文言があれば、項目の下に出すので上の message は出さない（同じ文言が 2 回出るのを避ける）。
  // それ以外（429・500・接続できない など）は上に出す。
  LoginFormState _fromApiError(ApiError e) {
    if (e.statusCode == 422 && e.errors.isNotEmpty) {
      return (isSubmitting: false, message: null, errors: e.errors);
    }
    return (isSubmitting: false, message: e.message, errors: const {});
  }

  static const LoginFormState _formatError = (
    isSubmitting: false,
    message: '応答の形が想定と違います。管理者にお問い合わせください。',
    errors: {},
  );
}
