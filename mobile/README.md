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
make mobile-run    # これ 1 つで、redis / mailpit・API（8002）・管理画面（3002）・シミュレータ（iPhone 17）をそろえてからアプリを起動
                   # 起動済みのものはそのまま使う。r でホットリロード、R でホットリスタート、q で終了
make mobile-down   # q の後も残る API と管理画面を止める（この作業ツリーのものだけ。redis / mailpit は make down）
make mobile-analyze                                                    # 静的解析（警告 0 件）
```
