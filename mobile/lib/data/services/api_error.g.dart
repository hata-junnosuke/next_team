// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'api_error.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_ApiError _$ApiErrorFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_ApiError', json, ($checkedConvert) {
      final val = _ApiError(
        message: $checkedConvert('message', (v) => v as String),
        errors: $checkedConvert(
          'errors',
          (v) =>
              (v as Map<String, dynamic>?)?.map(
                (k, e) => MapEntry(
                  k,
                  (e as List<dynamic>).map((e) => e as String).toList(),
                ),
              ) ??
              const <String, List<String>>{},
        ),
      );
      return val;
    });
