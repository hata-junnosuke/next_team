// ログインのトークン（Sanctum の API トークン）と、前回ログインした企業コードを端末に保存する所。
// アプリの中で flutter_secure_storage を使ってよいのはこのファイルだけ（ほかは TokenStorage を通す）。
// iOS では Keychain（OS が鍵をかけて持つ保存先）に入る。
// web で言うと、ログインの Cookie（httpOnly）をブラウザが持っていてくれる部分を、アプリが自分でやる所。
// Rails で言うと、session ストアの役（どこに何を置くかを 1 か所で決める）。
//
// なぜ SharedPreferences ではないか: SharedPreferences は暗号化しない設定ファイル（web の localStorage）で、
// 端末のバックアップや解析ツールから読めてしまう。トークンがあれば本人として API を叩けるので、
// OS の鍵つきの保存先に入れる（rules/common/security.md の mobile、docs/01 の 7A.4）。
//
// flutter_secure_storage: https://pub.dev/packages/flutter_secure_storage
// Keychain（Apple）: https://developer.apple.com/documentation/security/keychain-services

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class TokenStorage {
  // 保存先を外から渡せるようにしておく（Step 9 のテストで偽物に替えられる）。
  // 省略したら本物の FlutterSecureStorage を使う。
  // ?? は「左が null なら右」（JavaScript の ??）。
  TokenStorage({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  // 保存するときのキー（名前）。static const = クラスに 1 つだけの定数（TypeScript の static readonly）。
  static const String _tokenKey = 'worker_api_token';
  // 企業コードは秘密ではないが、パッケージ（shared_preferences）を足さないために同じ保存先の別のキーに置く
  // （issue #46 の Q2。docs/01 の 7A.4）。
  static const String _companyCodeKey = 'last_company_code';

  // 保存したトークン。なければ null（まだログインしていない・ログアウトした）。
  // await の付く読み書きは、OS の Keychain とやりとりするので Future になる。
  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> writeToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<void> deleteToken() => _storage.delete(key: _tokenKey);

  // 前回ログインした企業コード（T8）。ログアウトでは消さない（次のログインの入力欄に入れておくため）。
  Future<String?> readCompanyCode() => _storage.read(key: _companyCodeKey);

  Future<void> writeCompanyCode(String code) =>
      _storage.write(key: _companyCodeKey, value: code);
}
