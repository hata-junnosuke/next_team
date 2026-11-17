// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'my_applications_provider.dart';

// **************************************************************************
// RiverpodGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// ignore_for_file: type=lint, type=warning

@ProviderFor(myApplications)
final myApplicationsProvider = MyApplicationsFamily._();

final class MyApplicationsProvider
    extends
        $FunctionalProvider<
          AsyncValue<List<WorkerApplication>>,
          List<WorkerApplication>,
          FutureOr<List<WorkerApplication>>
        >
    with
        $FutureModifier<List<WorkerApplication>>,
        $FutureProvider<List<WorkerApplication>> {
  MyApplicationsProvider._({
    required MyApplicationsFamily super.from,
    required ScheduleTab super.argument,
  }) : super(
         retry: _noRetry,
         name: r'myApplicationsProvider',
         isAutoDispose: true,
         dependencies: null,
         $allTransitiveDependencies: null,
       );

  @override
  String debugGetCreateSourceHash() => _$myApplicationsHash();

  @override
  String toString() {
    return r'myApplicationsProvider'
        ''
        '($argument)';
  }

  @$internal
  @override
  $FutureProviderElement<List<WorkerApplication>> $createElement(
    $ProviderPointer pointer,
  ) => $FutureProviderElement(pointer);

  @override
  FutureOr<List<WorkerApplication>> create(Ref ref) {
    final argument = this.argument as ScheduleTab;
    return myApplications(ref, argument);
  }

  @override
  bool operator ==(Object other) {
    return other is MyApplicationsProvider && other.argument == argument;
  }

  @override
  int get hashCode {
    return argument.hashCode;
  }
}

String _$myApplicationsHash() => r'c2bbaff2ad9b5f310237e651842b269f50eebcb5';

final class MyApplicationsFamily extends $Family
    with
        $FunctionalFamilyOverride<
          FutureOr<List<WorkerApplication>>,
          ScheduleTab
        > {
  MyApplicationsFamily._()
    : super(
        retry: _noRetry,
        name: r'myApplicationsProvider',
        dependencies: null,
        $allTransitiveDependencies: null,
        isAutoDispose: true,
      );

  MyApplicationsProvider call(ScheduleTab tab) =>
      MyApplicationsProvider._(argument: tab, from: this);

  @override
  String toString() => r'myApplicationsProvider';
}
