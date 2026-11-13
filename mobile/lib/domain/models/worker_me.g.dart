// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'worker_me.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_WorkerCompany _$WorkerCompanyFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_WorkerCompany', json, ($checkedConvert) {
      final val = _WorkerCompany(
        id: $checkedConvert('id', (v) => (v as num).toInt()),
        name: $checkedConvert('name', (v) => v as String),
        code: $checkedConvert('code', (v) => v as String),
      );
      return val;
    });

_WorkerMe _$WorkerMeFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_WorkerMe', json, ($checkedConvert) {
      final val = _WorkerMe(
        id: $checkedConvert('id', (v) => (v as num).toInt()),
        name: $checkedConvert('name', (v) => v as String),
        email: $checkedConvert('email', (v) => v as String),
        company: $checkedConvert(
          'company',
          (v) => WorkerCompany.fromJson(v as Map<String, dynamic>),
        ),
      );
      return val;
    });
