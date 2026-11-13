// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_list_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(jobList)
final jobListProvider = JobListFamily._();

final class JobListProvider
    extends
        $FunctionalProvider<
          AsyncValue<JobPostingPage>,
          JobPostingPage,
          FutureOr<JobPostingPage>
        >
    with $FutureModifier<JobPostingPage>, $FutureProvider<JobPostingPage> {
  JobListProvider._({
    required JobListFamily super.from,
    required (DateFilter, int?) super.argument,
  }) : super(
         retry: _noRetry,
         name: r'jobListProvider',
         isAutoDispose: true,
         dependencies: null,
         $allTransitiveDependencies: null,
       );

  @override
  String debugGetCreateSourceHash() => _$jobListHash();

  @override
  String toString() {
    return r'jobListProvider'
        ''
        '$argument';
  }

  @$internal
  @override
  $FutureProviderElement<JobPostingPage> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<JobPostingPage> create(Ref ref) {
    final argument = this.argument as (DateFilter, int?);
    return jobList(ref, argument.$1, argument.$2);
  }

  @override
  bool operator ==(Object other) {
    return other is JobListProvider && other.argument == argument;
  }

  @override
  int get hashCode {
    return argument.hashCode;
  }
}

String _$jobListHash() => r'b3c96a76fe2a3240404948262a54ff525989c359';

final class JobListFamily extends $Family
    with
        $FunctionalFamilyOverride<
          FutureOr<JobPostingPage>,
          (DateFilter, int?)
        > {
  JobListFamily._()
    : super(
        retry: _noRetry,
        name: r'jobListProvider',
        dependencies: null,
        $allTransitiveDependencies: null,
        isAutoDispose: true,
      );

  JobListProvider call(DateFilter filter, int? locationId) =>
      JobListProvider._(argument: (filter, locationId), from: this);

  @override
  String toString() => r'jobListProvider';
}
