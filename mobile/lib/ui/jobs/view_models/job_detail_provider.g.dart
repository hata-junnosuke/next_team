// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_detail_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(jobDetail)
final jobDetailProvider = JobDetailFamily._();

final class JobDetailProvider
    extends
        $FunctionalProvider<
          AsyncValue<JobPosting?>,
          JobPosting?,
          FutureOr<JobPosting?>
        >
    with $FutureModifier<JobPosting?>, $FutureProvider<JobPosting?> {
  JobDetailProvider._({
    required JobDetailFamily super.from,
    required int super.argument,
  }) : super(
         retry: _noRetry,
         name: r'jobDetailProvider',
         isAutoDispose: true,
         dependencies: null,
         $allTransitiveDependencies: null,
       );

  @override
  String debugGetCreateSourceHash() => _$jobDetailHash();

  @override
  String toString() {
    return r'jobDetailProvider'
        ''
        '($argument)';
  }

  @$internal
  @override
  $FutureProviderElement<JobPosting?> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<JobPosting?> create(Ref ref) {
    final argument = this.argument as int;
    return jobDetail(ref, argument);
  }

  @override
  bool operator ==(Object other) {
    return other is JobDetailProvider && other.argument == argument;
  }

  @override
  int get hashCode {
    return argument.hashCode;
  }
}

String _$jobDetailHash() => r'9a61d169221910da29818952f883d63472d4c6bc';

final class JobDetailFamily extends $Family
    with $FunctionalFamilyOverride<FutureOr<JobPosting?>, int> {
  JobDetailFamily._()
    : super(
        retry: _noRetry,
        name: r'jobDetailProvider',
        dependencies: null,
        $allTransitiveDependencies: null,
        isAutoDispose: true,
      );

  JobDetailProvider call(int id) =>
      JobDetailProvider._(argument: id, from: this);

  @override
  String toString() => r'jobDetailProvider';
}
