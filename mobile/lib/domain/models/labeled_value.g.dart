// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'labeled_value.dart';

// **************************************************************************
// JsonSerializableGenerator
// **************************************************************************

_LabeledValue _$LabeledValueFromJson(Map<String, dynamic> json) =>
    $checkedCreate('_LabeledValue', json, ($checkedConvert) {
      final val = _LabeledValue(
        value: $checkedConvert('value', (v) => v as String),
        label: $checkedConvert('label', (v) => v as String),
      );
      return val;
    });
