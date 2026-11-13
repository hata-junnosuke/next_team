// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'location_filter_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(locationOptions)
final locationOptionsProvider = LocationOptionsProvider._();

final class LocationOptionsProvider
    extends
        $FunctionalProvider<
          AsyncValue<List<JobLocation>>,
          List<JobLocation>,
          FutureOr<List<JobLocation>>
        >
    with
        $FutureModifier<List<JobLocation>>,
        $FutureProvider<List<JobLocation>> {
  LocationOptionsProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'locationOptionsProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$locationOptionsHash();

  @$internal
  @override
  $FutureProviderElement<List<JobLocation>> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<List<JobLocation>> create(Ref ref) {
    return locationOptions(ref);
  }
}

String _$locationOptionsHash() => r'6910709093c1faa4dc2a303ca664a3bfedbeb1d6';

@ProviderFor(SelectedLocation)
final selectedLocationProvider = SelectedLocationProvider._();

final class SelectedLocationProvider
    extends $NotifierProvider<SelectedLocation, int?> {
  SelectedLocationProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'selectedLocationProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$selectedLocationHash();

  @$internal
  @override
  SelectedLocation create() => SelectedLocation();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(int? value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<int?>(value),
    );
  }
}

String _$selectedLocationHash() => r'b3445688e5e8ec5cd21e36661714951644b8ede5';

abstract class _$SelectedLocation extends $Notifier<int?> {
  int? build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<int?, int?>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<int?, int?>,
              int?,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
