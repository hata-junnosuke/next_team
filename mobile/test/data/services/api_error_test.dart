// ApiError（API の失敗の形 {message, errors} と状態コード）のテスト（Step 9。Step 6〜7 の復習）。
// 画面は ApiError の型を見ずに '$error'（toString）を出し、入力欄の下には firstErrorOf の文言を出す（docs/01 の 7A.4）。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_error.dart';

void main() {
  test('errors が省略されたら空の Map', () {
    // 401 の {"message": "Unauthenticated."} のように errors がない応答。
    final error = ApiError.fromJson({'message': 'Unauthenticated.'});

    expect(error.message, 'Unauthenticated.');
    expect(error.errors, isEmpty);
    // statusCode は JSON の中にないので fromJson では入らない（ApiClient が copyWith で入れる）。
    expect(error.statusCode, isNull);
  });

  test('toString は message を返す', () {
    const error = ApiError(statusCode: 422, message: '定員に達しています。');

    // 文字列の中の $error は toString() を呼ぶ（JavaScript のテンプレート文字列 `${error}` と同じ）。
    expect('$error', '定員に達しています。');
  });

  test('firstErrorOf は最初の文言、なければ null', () {
    final error = ApiError.fromJson({
      'message': '入力に誤りがあります。',
      'errors': {
        'email': ['メールアドレスを入力してください。', 'メールアドレスの形式が正しくありません。'],
      },
    });

    expect(error.firstErrorOf('email'), 'メールアドレスを入力してください。');
    expect(error.firstErrorOf('password'), isNull);
  });
}
