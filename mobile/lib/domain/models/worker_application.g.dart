// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'worker_application.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_WorkerApplication _$WorkerApplicationFromJson(Map<String, dynamic> json) =>
    $checkedCreate(
      '_WorkerApplication',
      json,
      ($checkedConvert) {
        final val = _WorkerApplication(
          id: $checkedConvert('id', (v) => (v as num).toInt()),
          jobPosting: $checkedConvert(
            'job_posting',
            (v) => ApplicationJobPosting.fromJson(v as Map<String, dynamic>),
          ),
          status: $checkedConvert(
            'status',
            (v) => LabeledValue.fromJson(v as Map<String, dynamic>),
          ),
          appliedAt: $checkedConvert(
            'applied_at',
            (v) => DateTime.parse(v as String),
          ),
          confirmedAt: $checkedConvert(
            'confirmed_at',
            (v) => v == null ? null : DateTime.parse(v as String),
          ),
          confirmedHourlyWage: $checkedConvert(
            'confirmed_hourly_wage',
            (v) => (v as num?)?.toInt(),
          ),
          confirmedTransportAllowance: $checkedConvert(
            'confirmed_transport_allowance',
            (v) => (v as num?)?.toInt(),
          ),
          cancelReason: $checkedConvert(
            'cancel_reason',
            (v) => v == null
                ? null
                : LabeledValue.fromJson(v as Map<String, dynamic>),
          ),
          cancelledAt: $checkedConvert(
            'cancelled_at',
            (v) => v == null ? null : DateTime.parse(v as String),
          ),
        );
        return val;
      },
      fieldKeyMap: const {
        'jobPosting': 'job_posting',
        'appliedAt': 'applied_at',
        'confirmedAt': 'confirmed_at',
        'confirmedHourlyWage': 'confirmed_hourly_wage',
        'confirmedTransportAllowance': 'confirmed_transport_allowance',
        'cancelReason': 'cancel_reason',
        'cancelledAt': 'cancelled_at',
      },
    );

_ApplicationJobPosting _$ApplicationJobPostingFromJson(
  Map<String, dynamic> json,
) => $checkedCreate(
  '_ApplicationJobPosting',
  json,
  ($checkedConvert) {
    final val = _ApplicationJobPosting(
      id: $checkedConvert('id', (v) => (v as num).toInt()),
      jobTitle: $checkedConvert('job_title', (v) => v as String),
      workDate: $checkedConvert(
        'work_date',
        (v) => DateTime.parse(v as String),
      ),
      startTime: $checkedConvert('start_time', (v) => v as String),
      endTime: $checkedConvert('end_time', (v) => v as String),
      location: $checkedConvert(
        'location',
        (v) => JobLocation.fromJson(v as Map<String, dynamic>),
      ),
    );
    return val;
  },
  fieldKeyMap: const {
    'jobTitle': 'job_title',
    'workDate': 'work_date',
    'startTime': 'start_time',
    'endTime': 'end_time',
  },
);
