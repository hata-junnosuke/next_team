// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'register_view_model.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(RegisterViewModel)
final registerViewModelProvider = RegisterViewModelProvider._();

final class RegisterViewModelProvider
    extends $NotifierProvider<RegisterViewModel, RegisterFormState> {
  RegisterViewModelProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'registerViewModelProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$registerViewModelHash();

  @$internal
  @override
  RegisterViewModel create() => RegisterViewModel();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(RegisterFormState value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<RegisterFormState>(value),
    );
  }
}

String _$registerViewModelHash() => r'f8a53743c90f4f47af776d4305e8fd9b7d81a8c1';

abstract class _$RegisterViewModel extends $Notifier<RegisterFormState> {
  RegisterFormState build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<RegisterFormState, RegisterFormState>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<RegisterFormState, RegisterFormState>,
              RegisterFormState,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
