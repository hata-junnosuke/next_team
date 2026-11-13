// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'logout_view_model.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(LogoutViewModel)
final logoutViewModelProvider = LogoutViewModelProvider._();

final class LogoutViewModelProvider
    extends $NotifierProvider<LogoutViewModel, bool> {
  LogoutViewModelProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'logoutViewModelProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$logoutViewModelHash();

  @$internal
  @override
  LogoutViewModel create() => LogoutViewModel();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(bool value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<bool>(value),
    );
  }
}

String _$logoutViewModelHash() => r'3b7bb2d9aa42153dea40c8847757ed6a15c20fe1';

abstract class _$LogoutViewModel extends $Notifier<bool> {
  bool build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<bool, bool>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<bool, bool>,
              bool,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
