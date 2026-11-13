// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_posting_page.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_JobPostingPage _$JobPostingPageFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_JobPostingPage', json, ($checkedConvert) {
      final val = _JobPostingPage(
        data: $checkedConvert(
          'data',
          (v) => (v as List<dynamic>)
              .map((e) => JobPosting.fromJson(e as Map<String, dynamic>))
              .toList(),
        ),
        meta: $checkedConvert(
          'meta',
          (v) => PageMeta.fromJson(v as Map<String, dynamic>),
        ),
      );
      return val;
    });

_PageMeta _$PageMetaFromJson(Map<String, dynamic> json) => $checkedCreate(
  '_PageMeta',
  json,
  ($checkedConvert) {
    final val = _PageMeta(
      currentPage: $checkedConvert('current_page', (v) => (v as num).toInt()),
      lastPage: $checkedConvert('last_page', (v) => (v as num).toInt()),
      perPage: $checkedConvert('per_page', (v) => (v as num).toInt()),
      total: $checkedConvert('total', (v) => (v as num).toInt()),
    );
    return val;
  },
  fieldKeyMap: const {
    'currentPage': 'current_page',
    'lastPage': 'last_page',
    'perPage': 'per_page',
  },
);
