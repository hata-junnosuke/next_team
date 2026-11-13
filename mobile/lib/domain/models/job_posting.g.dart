// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'job_posting.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_JobLocation _$JobLocationFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_JobLocation', json, ($checkedConvert) {
      final val = _JobLocation(
        id: $checkedConvert('id', (v) => (v as num).toInt()),
        name: $checkedConvert('name', (v) => v as String),
      );
      return val;
    });

_JobPosting _$JobPostingFromJson(Map<String, dynamic> json) => $checkedCreate(
  '_JobPosting',
  json,
  ($checkedConvert) {
    final val = _JobPosting(
      id: $checkedConvert('id', (v) => (v as num).toInt()),
      location: $checkedConvert(
        'location',
        (v) => JobLocation.fromJson(v as Map<String, dynamic>),
      ),
      jobTitle: $checkedConvert('job_title', (v) => v as String),
      workDate: $checkedConvert(
        'work_date',
        (v) => DateTime.parse(v as String),
      ),
      startTime: $checkedConvert('start_time', (v) => v as String),
      endTime: $checkedConvert('end_time', (v) => v as String),
      hourlyWage: $checkedConvert('hourly_wage', (v) => (v as num).toInt()),
      transportAllowance: $checkedConvert(
        'transport_allowance',
        (v) => (v as num).toInt(),
      ),
      capacity: $checkedConvert('capacity', (v) => (v as num).toInt()),
      remaining: $checkedConvert('remaining', (v) => (v as num).toInt()),
    );
    return val;
  },
  fieldKeyMap: const {
    'jobTitle': 'job_title',
    'workDate': 'work_date',
    'startTime': 'start_time',
    'endTime': 'end_time',
    'hourlyWage': 'hourly_wage',
    'transportAllowance': 'transport_allowance',
  },
);
