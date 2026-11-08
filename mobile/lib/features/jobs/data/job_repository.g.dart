// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_repository.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(jobRepository)
final jobRepositoryProvider = JobRepositoryProvider._();

final class JobRepositoryProvider
    extends $FunctionalProvider<JobRepository, JobRepository, JobRepository>
    with $Provider<JobRepository> {
  JobRepositoryProvider._()
    : super(
        from: null,
        argument: null,
        retry: null,
        name: r'jobRepositoryProvider',
        isAutoDispose: false,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$jobRepositoryHash();

  @$internal
  @override
  $ProviderElement<JobRepository> $createElement($ProviderPointer pointer) =>
      $ProviderElement(pointer);

  @override
  JobRepository create(Ref ref) {
    return jobRepository(ref);
  }

  /// {@macro riverpod.override_with_value}
  Override overrideWithValue(JobRepository value) {
    return $ProviderOverride(
      origin: this,
      providerOverride: $SyncValueProvider<JobRepository>(value),
    );
  }
}

String _$jobRepositoryHash() => r'fdb1e77aa03eae58d79a426204f5640554b47e6b';
