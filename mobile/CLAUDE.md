# `mobile/` — ワーカーアプリ（Flutter）の書き方

`mobile/` を書く前に必ず読む（ルートの `CLAUDE.md` の絶対ルール 10）。元は `docs/05_ワーカーアプリ学習計画.md` の 1・2・5 章。食い違ったら docs/05 を正として、ここを直す。

- 契約: `docs/01_技術仕様_next.md` の 5.3・6.11（API）と 7A（構成・ルート・規約）
- 読者は Flutter が初めて（Rails と React / Next.js の経験はある）。説明には React / Next.js（と必要なら Rails）の対応概念を一言添える

## ルール

1. **業務ルールと検証をアプリに書かない**。定員・二重応募・状態遷移・応募できるかは API が判定し、アプリは 422 の `message` を表示する（docs/01 の 7A.4）。残り枠は計算せず `remaining` を表示する。応募のボタンと「応募済み」「満員」の表示は、API の `can.apply`・`my_application`・`remaining` だけで出し分ける（Step 7 から。Step 3〜6 の偽データの画面は学習のためアプリで判定していた）
2. **`api/` のコードを読まない**。境界は docs/01 の 6.11 と 7A だけ。形がずれていたらアプリで吸収せず報告する
3. **パッケージは docs/01 の 1 章の表にあるものだけ**を、表の「いつ」の Step から使う。足したくなったら、メリットとデメリットの表を作って人に確認する。バージョンは導入時に固定し、`pubspec.lock` をコミットする
4. API は `data/services/` の `ApiClient` だけが `dio` で呼ぶ（`dio` を import するのは `data/services/` の `ApiClient` と `AuthInterceptor` だけ）。レスポンスはモデルの `fromJson` で型にしてから使う（Step 6 から）。モデルは Step 7 から `freezed` + `json_serializable` で生成し（`build.yaml` で `checked: true`）、生成した `fromJson` の失敗（`CheckedFromJsonException`）は `data/services/api_client.dart` の `parseModel()` で `FormatException` にそろえる（docs/01 の 7A.4）。生成物（`*.freezed.dart` `*.g.dart`）もコミットする
5. トークン・パスワード・招待コードをログに出さない。`print` は `flutter analyze` が止める（`avoid_print`）。`debugPrint` は止まらないので、使わない（`rules/common/security.md`）
6. `make mobile-analyze`（`flutter analyze`）が `No issues found!` でなければコミットしない。テストは Step 9 から `mobile/test/` に書く

## 進め方（まとめて実装し、解説を 1 枚の HTML に。docs/05 の 1 章）

| 手順 | 誰が | 何をするか |
|---|---|---|
| ① 実装・コミット | Claude | issue のタスク表の順に、コメント多めで 1 機能ずつ実装し、`flutter analyze` が 0 件であることを確かめて 1 コミットにする。止まらずに次の機能へ進む |
| ② 解説の HTML | Claude | 機能がそろったら、機能ごとの「何を実装したか」と「学ぶこと」を 1 枚の HTML にまとめて Orca で開く（下の書き方） |
| ③ 動かす | 人 | HTML の確認の手順にそって、シミュレータと管理画面で確かめる |
| ④ 質問・説明 | 人 | わからない所を聞く。「説明できるか」に答える |

- 1 機能は 15〜30 分で回る大きさにし、1 機能 = 1 コミットに分ける。**機能ごとに止まって人の返事を待たない**（issue #48 で変えた）。人が「1 機能ずつ止まって」と言ったときだけ、その形に戻す
- push・PR・CI も止まらずに進める（ルートの `CLAUDE.md` の絶対ルール 8）。PR の前に `spec-reviewer` ∥ `code-reviewer` を通す
- 解説の HTML の各節は **何を実装したか（ファイルと行、コミット）→ 何が新しいか → なぜこう書くか → React / Rails で言うと何か → 公式 URL** の順。新しい概念は初めて出たときだけ詳しく、2 回目からは一言。目次を付け、各節の最後に「説明できるか」を 1〜2 問、回答例（`<details>`）つきで出す。最後に、人が確かめる項目を確認の手順としてまとめる。レビューの周回などの作業の過程は載せない
- 公式 URL（docs.flutter.dev / api.flutter.dev / dart.dev / pub.dev / riverpod.dev/ja）は、開けることを確かめてから出す

## コメントの書き方（docs/05 の 2 章）

1. **ファイルの先頭**に「このファイルの役割」と「Next.js / React で言うと何か」を書く
2. **Dart の文法が初めて出てくる箇所**に一言添える（`?` `!` `late` `required` `final` / `const` / `=>` / `async*` / `sealed` / パターンマッチ など）。2 回目以降は書かない
3. **Widget ごとに**、何を表示するかと、なぜ `Stateless` / `Stateful` / `Consumer` を選んだのかを書く
4. **Riverpod の Provider ごとに**、何を持つか、いつ作り直されるか（`ref.watch` / `ref.invalidate`）を書く
5. **「なぜこう書くか」を優先**する。「何をしているか」はコードを見ればわかるので短く済ませる
6. 公式ドキュメントの該当ページの URL を、ファイルの先頭か初出の箇所に付ける

例:

```dart
// 募集一覧の画面。
// Next.js で言うと app/jobs/page.tsx に当たる。
// ただし Server Component ではなく、データの取得は Riverpod の Provider に任せる。
//
// ConsumerWidget = Provider を読める StatelessWidget。
// 自分では状態を持たず、Provider の値が変わると build() が呼ばれ直す
// （React で言うと、props / context が変わったときの再レンダー）。
class JobListScreen extends ConsumerWidget {
  // const コンストラクタ: 中身が変わらない Widget を使い回せるので、再描画が軽くなる。
  const JobListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // ref.watch = 値を読み、変化を購読する（React の useContext + 自動の再レンダー）。
    final jobs = ref.watch(jobListProvider);

    // AsyncValue は「読み込み中 / エラー / データ」の 3 状態を持つ。
    // when で 3 つ全部を書かないとコンパイルが通らないので、ローディングの表示を書き忘れない。
    return jobs.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorView(error: e),
      data: (list) => JobListView(jobs: list),
    );
  }
}
```

## 置き場所（docs/01 の 7A.1。Flutter 公式の case study の構成）

```
lib/
  main.dart                 起動する（その前に API_BASE_URL の検査とログインの状態の復元。Step 6）
  app.dart                  MaterialApp.router とテーマ（Next.js の app/layout.tsx）
  routing/router.dart       go_router
  config/dependencies.dart  どの実装を使うかを決める Provider（jobRepositoryProvider。Step 6 から apiClientProvider・tokenStorageProvider・authRepositoryProvider）
  utils/                    表示の形（format.dart）
  ui/core/                  共通の Widget（ui/。error_banner など）とテーマ（themes/）
  ui/<機能>/view_models/    その機能の Riverpod の Provider（Step 7 から応募の mutation と送信の関数も）
  ui/<機能>/widgets/        画面と Widget だけ
  domain/models/            モデルと enum（Flutter も Riverpod も import しない。Step 7 から freezed のモデル。import してよいのは freezed_annotation と json_annotation だけ）
  data/repositories/        リポジトリ（Riverpod を import しない。Step 6 からログインの状態を持つ AuthRepository、Step 7 から ApiJobRepository）
  data/services/            ApiClient・AuthInterceptor・TokenStorage・ApiError・device_name（Step 6）
```

依存の向き: `routing → widgets → view_models → config → data → domain`（一方通行。widgets は config と data を直接読まない）。`routing` は `redirect` でログインの状態（`authRepositoryProvider`）を読むためだけに `config` も使う（Step 6）。`ui` は `domain` のモデルと `utils` を直接使ってよい。`routing` と `widgets` は `ui/core/ui` も使う。`main.dart` は起動前の検査と復元のために `config` と `data/services`（`apiBaseUrl`）を使う。`view_models` は `on ApiError` のためだけに `data/services/api_error.dart` を import してよい（docs/01 の 7A.1）。`view_models` には Provider のほか、Riverpod の mutation（`Mutation`）と、それを動かす送信の関数も置く（Step 7 の応募の `apply_mutation.dart`）
