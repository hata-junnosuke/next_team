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

// ログイン画面と初回登録の画面のフォームの状態の型（register_view_model.dart もこれを使う）。
// typedef = 型に名前を付ける（TypeScript の type FormState = {...}）。
// ({...}) は「レコード」: クラスを書かずに、名前付きの値をまとめた型を作れる（TypeScript のオブジェクト型に近い）。
// - isSubmitting: 送っている途中か（T13。true の間はボタンを押せなくし、くるくるを出す。useFormStatus の pending）
// - message: 画面の上に出す文言（422 以外のエラーと、項目に結びつかないエラー）。なければ null
// - errors: 項目ごとの文言（422 の errors。ログインは company_code / email / password）。入力欄の下に出す
// 画面が ApiError（data/ の型）を知らずに済むように、ここで画面に出す形に変えておく。
typedef AuthFormState = ({
  bool isSubmitting,
  String? message,
  Map<String, List<String>> errors,
});

// 何も出していない状態。
const AuthFormState _initial = (isSubmitting: false, message: null, errors: {});

// ログイン画面に入力欄がある項目（422 の errors のキー）。これ以外のキーの文言は、入力欄の下に出せない。
const Set<String> _loginFields = {'company_code', 'email', 'password'};

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
  AuthFormState build() {
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
    } on Exception {
      // それ以外の思わぬ失敗（Keychain に書けなかった など）。黙って何も出さないと「押しても何も起きない」に見える。
      next = _unexpectedError;
    } finally {
      // finally: どう終わっても（思わぬ例外でも）送信中を解く。解かないとボタンが押せないままになる。
      // ref.mounted: await している間に、この Provider が捨てられていないか（ログイン画面が閉じられた など）。
      // 捨てられた後に state を書くと例外になるので確かめる（React の「アンマウント後に setState しない」と同じ）。
      // ログインに成功したときは、一覧に替わるのは次のフレームなので、ここではまだ true で、送信中を解く値が書かれる
      // （すぐ画面ごと消えるので害はない）。
      if (ref.mounted) state = next;
    }
  }

  // ApiError を画面に出す形に変える。
  // - 422 で、文言のキーがすべて画面の入力欄（_loginFields）にある: 項目の下に出し、上の message は出さない
  //   （同じ文言が 2 回出るのを避ける）
  // - 422 で、入力欄のないキー（device_name など）の文言がある: 項目の下に出せない分が消えないよう、その文言を上に出す
  // - それ以外（429・500・接続できない など）: 上に出す
  // 初回登録の view model にも同じ形のメソッドがある。7A.1 で view_models/ には Provider 以外の部品（共有の関数）を
  // 置かないと決めているので、小さな重複として残す（Step 7 で ApiError を freezed に移したが、公開の形は変えなかったので、
  // ここは分けたままにした。まとめるかは Step 9 でテストを書くときに決め直す）。
  AuthFormState _fromApiError(ApiError e) {
    if (e.statusCode == 422 && e.errors.isNotEmpty) {
      // 入力欄のないキーの文言（device_name など。アプリが自動で送る値なので、ふつうは起きない）。
      // e.message は Laravel が「最初の項目の文言（他 N 件）」の形で作るので、上に出すと入力欄の文言と重なり、
      // 入力欄のない項目の文言が見えないことがある。だから、入力欄のない項目の文言そのものを上に出す。
      final hidden = [
        for (final MapEntry(:key, :value) in e.errors.entries)
          if (!_loginFields.contains(key)) ...value,
      ];
      return (
        isSubmitting: false,
        message: hidden.isEmpty ? null : hidden.join('\n'),
        errors: e.errors,
      );
    }
    return (isSubmitting: false, message: e.message, errors: const {});
  }

  static const AuthFormState _formatError = (
    isSubmitting: false,
    message: '応答の形が想定と違います。管理者にお問い合わせください。',
    errors: {},
  );

  static const AuthFormState _unexpectedError = (
    isSubmitting: false,
    message: 'エラーが起きました。もう一度お試しください。',
    errors: {},
  );
}
