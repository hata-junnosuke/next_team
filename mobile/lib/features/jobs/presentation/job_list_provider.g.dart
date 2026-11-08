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
          AsyncValue<List<JobPosting>>,
          List<JobPosting>,
          FutureOr<List<JobPosting>>
        >
    with $FutureModifier<List<JobPosting>>, $FutureProvider<List<JobPosting>> {
  JobListProvider._({
    required JobListFamily super.from,
    required DateFilter super.argument,
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
        '($argument)';
  }

  @$internal
  @override
  $FutureProviderElement<List<JobPosting>> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<List<JobPosting>> create(Ref ref) {
    final argument = this.argument as DateFilter;
    return jobList(ref, argument);
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

String _$jobListHash() => r'0d140a78a49f72d5e4af96e1d15311435ec1853e';

final class JobListFamily extends $Family
    with $FunctionalFamilyOverride<FutureOr<List<JobPosting>>, DateFilter> {
  JobListFamily._()
    : super(
        retry: _noRetry,
        name: r'jobListProvider',
        dependencies: null,
        $allTransitiveDependencies: null,
        isAutoDispose: true,
      );

  JobListProvider call(DateFilter filter) =>
      JobListProvider._(argument: filter, from: this);

  @override
  String toString() => r'jobListProvider';
}
