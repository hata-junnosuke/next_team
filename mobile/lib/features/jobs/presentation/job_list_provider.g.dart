// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_list_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(jobList)
final jobListProvider = JobListProvider._();

final class JobListProvider
    extends
        $FunctionalProvider<
          AsyncValue<List<JobPosting>>,
          List<JobPosting>,
          FutureOr<List<JobPosting>>
        >
    with $FutureModifier<List<JobPosting>>, $FutureProvider<List<JobPosting>> {
  JobListProvider._()
    : super(
        from: null,
        argument: null,
        retry: _noRetry,
        name: r'jobListProvider',
        isAutoDispose: true,
        dependencies: null,
        $allTransitiveDependencies: null,
      );

  @override
  String debugGetCreateSourceHash() => _$jobListHash();

  @$internal
  @override
  $FutureProviderElement<List<JobPosting>> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<List<JobPosting>> create(Ref ref) {
    return jobList(ref);
  }
}

String _$jobListHash() => r'14ffeb20a906aa5963023e9c1db865e0540c49b0';
