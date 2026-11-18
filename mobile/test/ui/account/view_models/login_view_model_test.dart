// LoginViewModel（ログインのフォームの状態）のテスト（Step 9。Step 6 の Notifier の復習）。
// Notifier = 状態（state）と、それを変えるメソッド（submit）を持つ Provider。React で言うと useReducer を
// 画面の外に出したもの。画面は state を ref.watch して表示し、ボタンで submit を呼ぶだけ。
//
// authRepositoryProvider には本物の AuthRepository を入れ、その中の ApiClient だけを偽物にする（issue #50 の T7）。
// こうすると「ViewModel → AuthRepository → ApiClient」のつながりを、ネットワークなしで通しで確かめられる。

import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/data/repositories/auth_repository.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/data/services/token_storage.dart';
import 'package:worker_app/ui/account/view_models/login_view_model.dart';

import '../../../fakes/fake_api_client.dart';
import '../../../helpers/json_fixtures.dart';

const _loginPath = '/api/worker/login';

void main() {
  late FakeApiClient api;
  late AuthRepository auth;
  late ProviderContainer container;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    api = FakeApiClient();
    auth = AuthRepository(apiClient: api, tokenStorage: TokenStorage());
    container = ProviderContainer.test(
      overrides: [authRepositoryProvider.overrideWithValue(auth)],
      retry: (_, _) => null,
    );
    // loginViewModelProvider は使われなくなると捨てられる（autoDispose）。画面の代わりに購読しておく。
    container.listen(loginViewModelProvider, (_, _) {});
  });

  // 送信する。テストでは入力の中身は問わないので、決まった値を送る。
  Future<void> submit() => container
      .read(loginViewModelProvider.notifier)
      .submit(
        companyCode: 'ACME0001',
        email: 'worker@example.com',
        password: 'password',
      );

  AuthFormState state() => container.read(loginViewModelProvider);

  test('送信中は isSubmitting が true', () async {
    api
      ..gate = Completer<void>()
      ..respond('POST', _loginPath, loginResponseJson());

    final sending = submit();
    expect(state().isSubmitting, isTrue);

    api.gate!.complete();
    await sending;
    expect(state().isSubmitting, isFalse);
    expect(auth.isLoggedIn, isTrue);
  });

  test('送信中に 2 回呼んでも 1 回しか送らない', () async {
    api
      ..gate = Completer<void>()
      ..respond('POST', _loginPath, loginResponseJson());

    final first = submit();
    await submit();
    api.gate!.complete();
    await first;

    expect(api.sent, hasLength(1));
  });

  test('422 の欄のエラーは errors に、画面にない欄のエラーは message に入る', () async {
    api.respond(
      'POST',
      _loginPath,
      const ApiError(
        statusCode: 422,
        message: 'メールアドレスを入力してください。',
        errors: {
          'email': ['メールアドレスを入力してください。'],
          // 画面に欄がない項目（端末の名前）。欄の下に出せないので、上のバナー（message）に出す。
          'device_name': ['端末の名前は255文字以内にしてください。'],
        },
      ),
    );

    await submit();

    expect(state().errors['email'], ['メールアドレスを入力してください。']);
    expect(state().message, '端末の名前は255文字以内にしてください。');
    expect(state().isSubmitting, isFalse);
  });

  test('422 以外は message にサーバの文言', () async {
    api.respond(
      'POST',
      _loginPath,
      const ApiError(
        statusCode: 429,
        message: 'ログインの試行が多すぎます。しばらくしてからお試しください。',
      ),
    );

    await submit();

    expect(state().message, 'ログインの試行が多すぎます。しばらくしてからお試しください。');
    expect(state().errors, isEmpty);
  });

  test('FormatException は決まりの文言', () async {
    // 200 なのに token がない（契約のずれ）。AuthRepository が FormatException を投げる。
    api.respond('POST', _loginPath, {'data': <String, dynamic>{}});

    await submit();

    expect(state().message, '応答の形が想定と違います。管理者にお問い合わせください。');
    expect(auth.isLoggedIn, isFalse);
  });

  test('ログアウトされた理由を最初の state の message に出す', () async {
    // 別のテスト用の入れ物を作る（setUp の入れ物は、もう最初の state を作ってしまっているため）。
    final repository = AuthRepository(
      apiClient: FakeApiClient(),
      tokenStorage: TokenStorage(),
    );
    await TokenStorage().writeToken('old');
    await repository.restore();
    await repository.expireSession('このアカウントは現在利用できません。');

    final fresh = ProviderContainer.test(
      overrides: [authRepositoryProvider.overrideWithValue(repository)],
    );

    // build() で takeSignOutReason() を読み、最初の state の message にする。
    expect(fresh.read(loginViewModelProvider).message, 'このアカウントは現在利用できません。');
  });
}
