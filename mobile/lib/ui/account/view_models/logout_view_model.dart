// マイページの「ログアウト」の処理を持つ Provider（T11）。
// Next.js で言うと、ログアウトの Server Action と、送信中かどうか（useFormStatus の pending）を合わせたもの。

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';

part 'logout_view_model.g.dart';

// 何を持つか: ログアウトを送っている途中か（true の間はボタンを押せなくする）。
// いつ作り直されるか: keepAlive なしなので、マイページがなくなる（ログイン画面に移る）と捨てられる。
@riverpod
class LogoutViewModel extends _$LogoutViewModel {
  @override
  bool build() => false;

  Future<void> logout() async {
    // 送っている途中なら、2 回目は何もしない（ボタンの連打）。
    if (state) return;
    state = true;
    try {
      // 成功しても失敗しても、AuthRepository が未ログインにして知らせ、redirect がログイン画面に移す。
      await ref.read(authRepositoryProvider).logout();
    } finally {
      // ログイン画面に移ると、この Provider は捨てられている。捨てられた後に state を書くと例外になるので確かめる。
      if (ref.mounted) state = false;
    }
  }
}
