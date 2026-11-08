# `mobile/` — ワーカーアプリ（Flutter）の書き方

`mobile/` を書く前に必ず読む（ルートの `CLAUDE.md` の絶対ルール 10）。元は `docs/05_ワーカーアプリ学習計画.md` の 1・2・5 章。食い違ったら docs/05 を正として、ここを直す。

- 契約: `docs/01_技術仕様_next.md` の 5.3・6.11（API）と 7A（構成・ルート・規約）
- 読者は Flutter が初めて（Rails と React / Next.js の経験はある）。説明には React / Next.js（と必要なら Rails）の対応概念を一言添える

## ルール

1. **業務ルールと検証をアプリに書かない**。定員・二重応募・状態遷移・応募できるかは API が判定し、アプリは 422 の `message` を表示する（docs/01 の 7A.4）。Step 3 の偽データの画面では残り枠を計算せず `remaining` を表示する。満員・応募済みの判定は学習のためだけにアプリに置き、Step 7 で API の `can.apply` と `my_application` に置き換える
2. **`api/` のコードを読まない**。境界は docs/01 の 6.11 と 7A だけ。形がずれていたらアプリで吸収せず報告する
3. **パッケージは docs/01 の 1 章の表にあるものだけ**を、表の「いつ」の Step から使う。足したくなったら、メリットとデメリットの表を作って人に確認する。バージョンは導入時に固定し、`pubspec.lock` をコミットする
4. API は `data/services/` の `ApiClient` だけが `dio` で呼ぶ。レスポンスはモデルの `fromJson` で型にしてから使う（Step 6 から）
5. トークン・パスワード・招待コードをログに出さない。`print` は `flutter analyze` が止める（`avoid_print`）。`debugPrint` は止まらないので、使わない（`rules/common/security.md`）
6. `make mobile-analyze`（`flutter analyze`）が `No issues found!` でなければコミットしない。テストは Step 9 から `mobile/test/` に書く

## 進め方（1 機能 = 1 コミット。docs/05 の 1 章）

| 手順 | 誰が | 何をするか |
|---|---|---|
| ① 宣言 | Claude | 次に足す機能を 1 つだけ示す。画面で何が変わるかと、新しく出てくる概念を先に言う（公式 URL つき） |
| ② 実装・コミット | Claude | コメント多めで実装し、`flutter analyze` が 0 件であることを確かめて 1 コミットにする |
| ③ 動かす | 人 | シミュレータで `r`（ホットリロード）か `R`（ホットリスタート）を押し、「確認」を通す |
| ④ 変更点の案内 | Claude | そのコミットの差分を案内する（下の書き方） |
| ⑤ 質問・説明 | 人 | わからない所を聞く。「説明できるか」に答える。済んだら「次」と言う |

- **「次」と言われるまで、次の機能に進まない**。1 機能は 15〜30 分で回る大きさにする
- push・PR・CI は、そのステップの機能がそろってから止まらずに進める（ルートの `CLAUDE.md` の絶対ルール 8）
- 変更点の案内は **ファイルと行 → 何が新しいか → なぜこう書くか → React / Rails で言うと何か → 公式 URL** の順。新しい概念は初めて出たときだけ詳しく、2 回目からは一言。最後に「説明できるか」を 1〜2 問出す
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
  main.dart                 起動するだけ
  app.dart                  MaterialApp.router とテーマ（Next.js の app/layout.tsx）
  routing/router.dart       go_router
  config/dependencies.dart  どの実装を使うかを決める Provider（jobRepositoryProvider）
  utils/                    表示の形（format.dart）
  ui/core/                  共通の Widget（ui/）とテーマ（themes/）
  ui/<機能>/view_models/    その機能の Riverpod の Provider だけ
  ui/<機能>/widgets/        画面と Widget だけ
  domain/models/            モデルと enum（Flutter も Riverpod も import しない）
  data/repositories/        リポジトリ（Riverpod を import しない）
  data/services/            ApiClient など（Step 6）
```

依存の向き: `routing → widgets → view_models → config → data → domain`（一方通行。widgets は config と data を直接読まない）
