// 画面の上に出すエラーの帯。ログイン画面と初回登録の画面で使う（422 以外のエラーと、401 で戻されたときの理由）。
// 複数の画面で使う Widget なので ui/core/ui/ に置く（docs/01 の 7A.1）。状態は持たない。
// web で言うと components/ui/ のアラートの部品。

import 'package:flutter/material.dart';

// 文言を受け取って描くだけなので Stateless。
class ErrorBanner extends StatelessWidget {
  const ErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    // Theme.of(context): アプリのテーマ（色など）を読む（React の useContext(ThemeContext)）。
    final colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: colors.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(message, style: TextStyle(color: colors.onErrorContainer)),
    );
  }
}
