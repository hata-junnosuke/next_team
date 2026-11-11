// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'me_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(me)
final meProvider = MeProvider._();

final class MeProvider
    extends
        $FunctionalProvider<AsyncValue<WorkerMe>, WorkerMe, FutureOr<WorkerMe>>
    with $FutureModifier<WorkerMe>, $FutureProvider<WorkerMe> {
  MeProvider._()
    : super(
        from: null,
        argument: null,
        retry: _noRetry,
        name: r'meProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$meHash();

  @$internal
  @override
  $FutureProviderElement<WorkerMe> $createElement($ProviderPointer pointer) =>
      $FutureProviderElement(pointer);

  @override
  FutureOr<WorkerMe> create(Ref ref) {
    return me(ref);
  }
}

String _$meHash() => r'38172bf65999c954f7a830f75934531445f4eb13';
