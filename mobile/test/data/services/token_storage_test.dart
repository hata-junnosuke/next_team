// TokenStorage のテスト（Step 9。Step 6 のトークンの保存の復習）。
// FlutterSecureStorage.setMockInitialValues: パッケージが用意している「テスト用の置き場」に切り替える。
// これを呼ぶと、本物の Keychain（iOS）ではなく、メモリの中の Map に読み書きする（Keychain に触れない）。
// Rails で言うと、テストの環境で Rails.cache を :memory_store にするのに近い。
// https://pub.dev/documentation/flutter_secure_storage/latest/flutter_secure_storage/FlutterSecureStorage/setMockInitialValues.html

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/token_storage.dart';

void main() {
  setUp(() {
    // 各テストの前に空の置き場にする（前のテストのトークンが残らないように）。
    FlutterSecureStorage.setMockInitialValues({});
  });

  test('トークンを消しても企業コードは残る', () async {
    final storage = TokenStorage();
    await storage.writeToken('secret-token');
    await storage.writeCompanyCode('ACME0001');

    await storage.deleteToken();

    expect(await storage.readToken(), isNull);
    // 企業コードは秘密ではなく、次のログインの入力欄に入れておくために残す（docs/01 の 7A.4 の企業コード）。
    expect(await storage.readCompanyCode(), 'ACME0001');
  });
}
