// 初回登録の画面。パスは /register（下タブの外。docs/01 の 7A.3）。
// 管理者が登録したワーカーが、メールで届いた招待コードを使って自分のパスワードを決める（F-28。POST /api/worker/register）。
// 成功するとそのままログインした状態になり、募集の一覧に移る。
// Next.js で言うと app/register/page.tsx と、その中のフォームに当たる。
// 形はログイン画面（login_screen.dart）と同じ。入力の検証はアプリに書かず、API の 422 を項目の下に出す。

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/ui/error_banner.dart';
import '../view_models/register_view_model.dart';

// 入力欄の中身（TextEditingController）を持つので ConsumerStatefulWidget（ログイン画面と同じ理由）。
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _passwordConfirmation = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _code.dispose();
    _password.dispose();
    _passwordConfirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    await ref
        .read(registerViewModelProvider.notifier)
        .submit(
          email: _email.text.trim(),
          code: _code.text.trim(),
          password: _password.text,
          passwordConfirmation: _passwordConfirmation.text,
        );
  }

  @override
  Widget build(BuildContext context) {
    final form = ref.watch(registerViewModelProvider);
    String? errorOf(String field) => form.errors[field]?.firstOrNull;

    return Scaffold(
      // 戻るボタンは AppBar が自動で出す（ログイン画面から push で来たので、戻るとログイン画面）。
      appBar: AppBar(title: const Text('初回登録')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (form.message != null) ...[
                ErrorBanner(message: form.message!),
                const SizedBox(height: 16),
              ],
              const Text('管理者から届いたメールの招待コードで、パスワードを決めます。'),
              const SizedBox(height: 12),
              TextField(
                controller: _email,
                decoration: InputDecoration(
                  labelText: 'メールアドレス',
                  helperText: '招待のメールが届いたアドレス',
                  errorText: errorOf('email'),
                ),
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                autofillHints: const [AutofillHints.email],
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _code,
                decoration: InputDecoration(
                  labelText: '招待コード',
                  errorText: errorOf('code'),
                ),
                autocorrect: false,
                // 招待コードはログに出さない値（rules/common/security.md）。入力欄はふつうに見せる（打ち間違いに気づけるように）。
                // OS の自動入力（ワンタイムコード）の候補にする。
                autofillHints: const [AutofillHints.oneTimeCode],
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _password,
                decoration: InputDecoration(
                  // 文字数の決まり（8 文字以上）は書かない。決まりは API にあり、足りなければ 422 の文言が出る
                  // （ここに書くと、API の決まりが変わったときに表示とずれる。CLAUDE.md の絶対ルール 1）。
                  labelText: 'パスワード',
                  errorText: errorOf('password'),
                ),
                obscureText: true,
                // newPassword: 新しいパスワードの欄だと OS に伝える（強いパスワードの提案が出る）。
                autofillHints: const [AutofillHints.newPassword],
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _passwordConfirmation,
                decoration: InputDecoration(
                  labelText: 'パスワード（確認用）',
                  // 一致しないときの文言は Laravel の confirmed が password のキーに入れる。
                  // 念のため password_confirmation のキーも出す。
                  errorText: errorOf('password_confirmation'),
                ),
                obscureText: true,
                autofillHints: const [AutofillHints.newPassword],
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 24),
              // 送っている途中は押せなくし、くるくるを出す（T13。ログイン画面と同じ）。
              FilledButton(
                onPressed: form.isSubmitting ? null : _submit,
                child: form.isSubmitting
                    ? const SizedBox.square(
                        dimension: 20,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Text('登録する'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
