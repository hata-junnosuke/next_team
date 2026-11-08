// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'applied_jobs_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(AppliedJobs)
final appliedJobsProvider = AppliedJobsProvider._();

final class AppliedJobsProvider
    extends $NotifierProvider<AppliedJobs, Set<int>> {
  AppliedJobsProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'appliedJobsProvider',
        isAutoDispose: false,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$appliedJobsHash();

  @$internal
  @override
  AppliedJobs create() => AppliedJobs();

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(Set<int> value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<Set<int>>(value),
    );
  }
}

String _$appliedJobsHash() => r'77a18e8c6c949ec49e47925aba03de6512be5964';

abstract class _$AppliedJobs extends $Notifier<Set<int>> {
  Set<int> build();
  @$mustCallSuper
  @override
  WhenComplete runBuild() {
    final ref = this.ref as $Ref<Set<int>, Set<int>>;
    final element =
        ref.element
            as $ClassProviderElement<
              AnyNotifier<Set<int>, Set<int>>,
              Set<int>,
              Object?,
              Object?
            >;
    return element.handleCreate(ref, build);
  }
}
