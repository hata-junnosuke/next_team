// ログイン画面。パスは /login（下タブの外。docs/01 の 7A.3）。
// Next.js で言うと app/login/page.tsx と、その中のフォーム（Client Component）に当たる。
// 企業コード・メールアドレス・パスワードを入れて POST /api/worker/login を送る（6.11）。
//
// 入力の検証（空・メールの形など）はアプリに書かない。送ったら API が 422 で返すので、それを項目の下に出す
// （CLAUDE.md の絶対ルール 1。TextFormField の検証の引数も使わない）。
//
// TextField: https://api.flutter.dev/flutter/material/TextField-class.html
// TextEditingController: https://api.flutter.dev/flutter/widgets/TextEditingController-class.html
// 入力欄の作例: https://docs.flutter.dev/cookbook/forms/text-field-changes
// ConsumerStatefulWidget: https://riverpod.dev/ja/docs/concepts2/consumers

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../view_models/login_view_model.dart';

// ConsumerStatefulWidget = Provider を読める StatefulWidget。
// 入力欄の中身（TextEditingController）は画面が持つ State なので Stateful にする。
// 送信の結果（エラー）は Provider（loginViewModelProvider）が持つ。
// React で言うと、useRef で持つ入力欄と、useActionState の state を両方使うフォームのコンポーネント。
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  // State を作る。Stateful の Widget は「設定（この class）」と「状態（下の State）」の 2 つに分かれている。
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  // TextEditingController: 入力欄の文字を読み書きする道具（React の useRef で input の値を持つのに近い）。
  // final なので作り直さない。画面が捨てられるときに dispose で片付ける。
  final _companyCode = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  // dispose: State が捨てられるときに 1 回呼ばれる（React の useEffect の後片付け return () => {...}）。
  // Controller は捨てないと聞き手が残る（メモリが漏れる）ので、ここで片付ける。
  @override
  void dispose() {
    _companyCode.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  // 送信ボタンを押したとき。
  Future<void> _submit() async {
    // キーボードを閉じる（フォーカスを外す）。
    FocusScope.of(context).unfocus();
    await ref
        .read(loginViewModelProvider.notifier)
        .submit(
          // trim: 前後の空白を取る。見た目の整え（入力の検証ではない）。パスワードは空白も含めてそのまま送る。
          companyCode: _companyCode.text.trim(),
          email: _email.text.trim(),
          password: _password.text,
        );
  }

  @override
  Widget build(BuildContext context) {
    // 送信の結果。変わるとこの build がやり直され、エラーが出る。
    final form = ref.watch(loginViewModelProvider);

    // その項目の最初の文言（なければ null）。errorText に null を渡すとエラーの表示が消える。
    String? errorOf(String field) => form.errors[field]?.firstOrNull;

    return Scaffold(
      appBar: AppBar(title: const Text('ログイン')),
      // SafeArea: ノッチや角の丸みで隠れない範囲に収める。
      // SingleChildScrollView: キーボードが出て高さが足りなくなってもスクロールできるようにする。
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            // 横いっぱいに広げる（CSS の align-items: stretch）。
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // 上に出すエラー（422 以外）。if を [ ] の中に書くと、条件が合うときだけ要素を足せる（collection if）。
              if (form.message != null) ...[
                // ... はスプレッド（[ ] の中身を展開する。JavaScript の ...[a, b] と同じ）。
                _ErrorBanner(message: form.message!),
                const SizedBox(height: 16),
              ],
              TextField(
                controller: _companyCode,
                decoration: InputDecoration(
                  labelText: '企業コード',
                  helperText: '管理者から伝えられたコード',
                  errorText: errorOf('company_code'),
                ),
                // 入力を大文字にする（キーボードの設定。値の検証ではない。API も大文字にそろえて比べる）。
                textCapitalization: TextCapitalization.characters,
                autocorrect: false,
                // キーボードの「次へ」で次の欄に移る。
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _email,
                decoration: InputDecoration(
                  labelText: 'メールアドレス',
                  errorText: errorOf('email'),
                ),
                // メール用のキーボード（@ が出る）。
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                // OS のパスワード管理（iCloud キーチェーン）に「メールの欄」だと伝える。
                autofillHints: const [AutofillHints.email],
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _password,
                decoration: InputDecoration(
                  labelText: 'パスワード',
                  errorText: errorOf('password'),
                ),
                // obscureText: 入力を ● で隠す（HTML の type="password"）。
                obscureText: true,
                autofillHints: const [AutofillHints.password],
                textInputAction: TextInputAction.done,
                // キーボードの「完了」でも送る。(_) は「受け取るが使わない」引数（入力された文字）。
                onSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 24),
              FilledButton(onPressed: _submit, child: const Text('ログイン')),
            ],
          ),
        ),
      ),
    );
  }
}

// 画面の上に出すエラーの帯。この画面でしか使わないので、このファイルに置き、_ で外から隠す。
// 状態を持たないので Stateless。
class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

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
