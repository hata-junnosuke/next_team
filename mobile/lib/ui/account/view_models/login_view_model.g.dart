// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'login_view_model.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(lastCompanyCode)
final lastCompanyCodeProvider = LastCompanyCodeProvider._();

final class LastCompanyCodeProvider
    extends $FunctionalProvider<AsyncValue<String?>, String?, FutureOr<String?>>
    with $FutureModifier<String?>, $FutureProvider<String?> {
  LastCompanyCodeProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'lastCompanyCodeProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$lastCompanyCodeHash();

  @$internal
  @override
  $FutureProviderElement<String?> $createElement($ProviderPointer pointer) =>
      $FutureProviderElement(pointer);

  @override
  FutureOr<String?> create(Ref ref) {
    return lastCompanyCode(ref);
  }
}

String _$lastCompanyCodeHash() => r'ebf53817b1ce759a3072f8bae67292ecec8cf22a';

@ProviderFor(LoginViewModel)
final loginViewModelProvider = LoginViewModelProvider._();

final class LoginViewModelProvider
    extends $NotifierProvider<LoginViewModel, AuthFormState> {
  LoginViewModelProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'loginViewModelProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$loginViewModelHash();

  @$internal
  @override
  LoginViewModel create() => LoginViewModel();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(AuthFormState value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<AuthFormState>(value),
    );
  }
}

String _$loginViewModelHash() => r'b53ce27970ffafc8da982caf8813acaedba85b04';

abstract class _$LoginViewModel extends $Notifier<AuthFormState> {
  AuthFormState build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<AuthFormState, AuthFormState>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<AuthFormState, AuthFormState>,
              AuthFormState,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
