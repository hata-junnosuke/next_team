# worker_app（ワーカーアプリ）

ワーカーがスマホで募集を探して応募する Flutter アプリ。計画は [`docs/05_ワーカーアプリ学習計画.md`](../docs/05_ワーカーアプリ学習計画.md)、契約は [`docs/01_技術仕様_next.md`](../docs/01_技術仕様_next.md) の 6.11 と 7A。書き方は [`CLAUDE.md`](CLAUDE.md)。

## 導入したバージョン

| 項目 | バージョン |
|---|---|
| Flutter | 3.47.8（stable） |
| Dart SDK（`pubspec.yaml` の `environment`） | ^3.13.7 |
| 作成コマンド | `flutter create --org io.github.hatajunnosuke --project-name worker_app --platforms ios,android mobile` |

## 動かし方（`next_team/` から）

```bash
make mobile-run    # これ 1 つで、Redis・Mailpit・API・管理画面・Horizon・スケジューラ・シミュレータ（iPhone 17）をそろえてアプリを起動
                   # ポートは場所で決まる（main 8002/3002、issue #N の worktree 8100+N/3100+N、review 8012/3012）。アプリもその API につなぐ
                   # 起動済みのものはそのまま使う。ログインの情報も出る。r でホットリロード、R でホットリスタート、q で終了
make dev-down      # q の後も残る API・管理画面・Horizon・スケジューラを止める（この場所のものだけ。Redis・Mailpit は make down）
make mobile-analyze                                                    # 静的解析（警告 0 件）
```
