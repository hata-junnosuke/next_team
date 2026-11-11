// 初回登録の画面の状態（送信の結果のエラー）と、送信の処理を持つ Provider（T12）。
// 形は login_view_model.dart と同じ（Server Action と useActionState の state）。
// 画面は AuthRepository や ApiClient を直接さわらない（docs/01 の 7A.1）。

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../data/services/api_error.dart';
import 'login_view_model.dart';

part 'register_view_model.g.dart';

// フォームの状態の型は、ログイン画面と同じ AuthFormState（login_view_model.dart）を使う。
const AuthFormState _initial = (
  isSubmitting: false,
  message: null,
  errors: {},
);

// 初回登録の画面に入力欄がある項目（422 の errors のキー）。
const Set<String> _registerFields = {
  'email',
  'code',
  'password',
  'password_confirmation',
};

// 何を持つか: 直前の送信のエラー（なければ空）。
// いつ作り直されるか: keepAlive なしなので、初回登録の画面を離れると捨てられる（前のエラーが残らない）。
@riverpod
class RegisterViewModel extends _$RegisterViewModel {
  @override
  AuthFormState build() => _initial;

  // 送信。入力の検証（メールの形・8 文字・確認用のパスワードとの一致）はアプリに書かず、API の 422 に任せる。
  // 成功したら AuthRepository がトークンと企業コードを保存してログイン済みにし、redirect が一覧に移す。
  Future<void> submit({
    required String email,
    required String code,
    required String password,
    required String passwordConfirmation,
  }) async {
    // 送っている途中なら何もしない（T13。ログイン画面と同じ）。
    if (state.isSubmitting) return;
    state = (isSubmitting: true, message: null, errors: const {});

    var next = _initial;
    try {
      await ref
          .read(authRepositoryProvider)
          .register(
            email: email,
            code: code,
            password: password,
            passwordConfirmation: passwordConfirmation,
          );
    } on ApiError catch (e) {
      // 422 で項目ごとの文言があれば項目の下に、それ以外（429・接続できない など）は上に出す（login_view_model.dart）。
      next = _fromApiError(e);
    } on FormatException {
      next = _formatError;
    } on Exception {
      next = _unexpectedError;
    } finally {
      // どう終わっても送信中を解く。画面を離れて捨てられていたら書かない（login_view_model.dart と同じ）。
      if (ref.mounted) state = next;
    }
  }

  // ApiError を画面に出す形に変える。
  // - 422 で、文言のキーがすべて画面の入力欄（_registerFields）にある: 項目の下に出し、上の message は出さない
  //   （同じ文言が 2 回出るのを避ける）
  // - 422 で、入力欄のないキー（device_name など）の文言がある: 項目の下に出せない分が消えないよう、上にも message を出す
  // - それ以外（429・500・接続できない など）: 上に出す
  // login_view_model.dart の _fromApiError と同じ（重複を残す理由もそちら）。
  AuthFormState _fromApiError(ApiError e) {
    if (e.statusCode == 422 && e.errors.isNotEmpty) {
      final allShown = e.errors.keys.every(_registerFields.contains);
      return (
        isSubmitting: false,
        message: allShown ? null : e.message,
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
