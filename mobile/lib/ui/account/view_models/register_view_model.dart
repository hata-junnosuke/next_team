// 初回登録の画面の状態（送信の結果のエラー）と、送信の処理を持つ Provider（T12）。
// 形は login_view_model.dart と同じ（Server Action と useActionState の state）。
// 画面は AuthRepository や ApiClient を直接さわらない（docs/01 の 7A.1）。

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../data/services/api_error.dart';

part 'register_view_model.g.dart';

// フォームの状態の型（login_view_model.dart の LoginFormState と同じ形のレコード）。
// - isSubmitting: 送っている途中か（T13）
// - message: 画面の上に出す文言
// - errors: 項目ごとの文言（422 の errors。email / code / password）
typedef RegisterFormState = ({
  bool isSubmitting,
  String? message,
  Map<String, List<String>> errors,
});

const RegisterFormState _initial = (
  isSubmitting: false,
  message: null,
  errors: {},
);

// 何を持つか: 直前の送信のエラー（なければ空）。
// いつ作り直されるか: keepAlive なしなので、初回登録の画面を離れると捨てられる（前のエラーが残らない）。
@riverpod
class RegisterViewModel extends _$RegisterViewModel {
  @override
  RegisterFormState build() => _initial;

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
      // 422 で項目ごとの文言があれば項目の下に、それ以外（429・接続できない など）は上に出す。
      next = e.statusCode == 422 && e.errors.isNotEmpty
          ? (isSubmitting: false, message: null, errors: e.errors)
          : (isSubmitting: false, message: e.message, errors: const {});
    } on FormatException {
      next = (
        isSubmitting: false,
        message: '応答の形が想定と違います。管理者にお問い合わせください。',
        errors: const {},
      );
    } finally {
      // どう終わっても送信中を解く。画面を離れて捨てられていたら書かない（login_view_model.dart と同じ）。
      if (ref.mounted) state = next;
    }
  }
}
