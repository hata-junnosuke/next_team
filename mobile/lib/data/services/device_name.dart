// ログインと初回登録で送る device_name（API がトークンの名前にする。docs/01 の 5.3・6.11）。
// 管理者が「どの端末のトークンか」を見分けるための名前で、秘密ではない。
// パッケージ（device_info_plus）を足さず、dart:io の Platform から OS の名前と版で作る（issue #46 の Q3）。
// 例: "ios Version 18.2 (Build 22C150)"。API の上限（255 文字）を超えないように切る。
//
// Platform: https://api.dart.dev/dart-io/Platform-class.html

// dart:io: ファイルや OS の情報を扱う Dart の標準ライブラリ（Node.js の os モジュールに近い）。
import 'dart:io';

String deviceName() {
  // operatingSystem は "ios" や "android"、operatingSystemVersion は OS の版の文字列。
  // $変数 / ${式} は文字列の中に値を埋め込む書き方（JavaScript のテンプレートリテラル `${x}`）。
  final name = '${Platform.operatingSystem} ${Platform.operatingSystemVersion}'
      .trim();
  // 255 文字を超えたら先頭の 255 文字だけにする（6.11 の device_name は 255 文字以内）。
  return name.length <= 255 ? name : name.substring(0, 255);
}
