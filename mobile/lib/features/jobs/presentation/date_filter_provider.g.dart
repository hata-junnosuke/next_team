// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'date_filter_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(SelectedDateFilter)
final selectedDateFilterProvider = SelectedDateFilterProvider._();

final class SelectedDateFilterProvider
    extends $NotifierProvider<SelectedDateFilter, DateFilter> {
  SelectedDateFilterProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'selectedDateFilterProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$selectedDateFilterHash();

  @$internal
  @override
  SelectedDateFilter create() => SelectedDateFilter();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(DateFilter value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<DateFilter>(value),
    );
  }
}

String _$selectedDateFilterHash() =>
    r'a744ab5841043da76e4020a07138478b9da54186';

abstract class _$SelectedDateFilter extends $Notifier<DateFilter> {
  DateFilter build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<DateFilter, DateFilter>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<DateFilter, DateFilter>,
              DateFilter,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
