# コーディングスタイル（常時適用）

- コメント・コミットメッセージ・ドキュメント・UI 文言は日本語。識別子は英語
- UI 文言とエラーメッセージは要件の用語（企業 / 管理者 / 拠点 / ワーカー / 募集 / 応募 / 確定 / 却下 / キャンセル / 勤怠 / 報酬）を使う
- 不要な抽象化をしない。api はサービス + Eloquent、web は Server Component + Server Action で足りる。**`api/` と `web/` では**、リポジトリ層・状態管理ライブラリ・UI ライブラリは作らない／入れない
- `mobile/` は docs/01 の 7A と `mobile/CLAUDE.md` に従う（API を呼ぶリポジトリ層と、状態管理の Riverpod を使う。docs/00 の決定 58）。コメントは多め（docs/05 の 2 章）
- 1 ファイル 300 行を目安に分割を検討する
- デバッグ出力を残さない: api は `dd()` `dump()` `var_dump()` `ray()`、web は `console.log()`（PostToolUse フックが差し戻す）、mobile は `print()` と `debugPrint()`（`print()` は `analysis_options.yaml` の `avoid_print` で `flutter analyze` が警告する。`debugPrint()` は警告されないのでレビューで見る。フックはまだ mobile を見ない）
- マジック文字列を書かない。状態は Enum（api）と API が返す `{value, label}`（web・mobile）、設定は `config()` と環境変数（mobile は `--dart-define`）
- **このリポジトリの外（親フォルダの他のプロジェクト）のコードは読まない**。リポジトリの外で読むのは要件 `../00_要件定義.md` だけ
- パッケージを足さない。使ってよいものは `docs/01_技術仕様_next.md` の構成の表にあるものだけ（mobile も同じ。表の「いつ」の Step より前に入れない）
- コマンドは `make` 経由で叩く（`make help`）。`api/` `web/` `mobile/` に入って叩くのは `make` にないものだけ（mobile の `flutter run` の中のキー操作など）
