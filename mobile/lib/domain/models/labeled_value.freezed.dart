// GENERATED CODE - DO NOT MODIFY BY HAND
// coverage:ignore-file
// ignore_for_file: type=lint, type=warning, deprecated_member_use, deprecated_member_use_from_same_package
// ignore_for_file: unused_element, deprecated_member_use, deprecated_member_use_from_same_package, use_function_type_syntax_for_parameters, unnecessary_const, avoid_init_to_null, invalid_override_different_default_values_named, prefer_expression_function_bodies, annotate_overrides, invalid_annotation_target, unnecessary_question_mark

part of 'labeled_value.dart';

// **************************************************************************
// FreezedGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// dart format off
T _$identity<T>(T value) => value;

/// @nodoc
mixin _$LabeledValue {

 String get value; String get label;
/// Create a copy of LabeledValue
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<LabeledValue> get copyWith => _$LabeledValueCopyWithImpl<LabeledValue>(this as LabeledValue, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as LabeledValue;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is LabeledValue&&(identical(other.value, _this.value) || other.value == _this.value)&&(identical(other.label, _this.label) || other.label == _this.label));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as LabeledValue;
  return Object.hash(runtimeType,_this.value,_this.label);
}

@override
String toString() {
  final _this = this as LabeledValue;
  return 'LabeledValue(value: ${_this.value}, label: ${_this.label})';
}


}

/// @nodoc
abstract mixin class $LabeledValueCopyWith<$Res>  {
  factory $LabeledValueCopyWith(LabeledValue value, $Res Function(LabeledValue) _then) = _$LabeledValueCopyWithImpl;
@useResult
$Res call({
 String value, String label
});




}
/// @nodoc
class _$LabeledValueCopyWithImpl<$Res>
    implements $LabeledValueCopyWith<$Res> {
  _$LabeledValueCopyWithImpl(this._self, this._then);

  final LabeledValue _self;
  final $Res Function(LabeledValue) _then;

/// Create a copy of LabeledValue
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? value = null,Object? label = null,}) {
  return _then(LabeledValue(
value: null == value ? _self.value : value // ignore: cast_nullable_to_non_nullable
as String,label: null == label ? _self.label : label // ignore: cast_nullable_to_non_nullable
as String,
  ));
}

}


/// Adds pattern-matching-related methods to [LabeledValue].
extension LabeledValuePatterns on LabeledValue {
/// A variant of `map` that fallback to returning `orElse`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _LabeledValue value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _LabeledValue() when $default != null:
return $default(_that);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// Callbacks receives the raw object, upcasted.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case final Subclass2 value:
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _LabeledValue value)  $default,){
final _that = this;
switch (_that) {
case _LabeledValue():
return $default(_that);case _:
  throw StateError('Unexpected subclass');

}
}
/// A variant of `map` that fallback to returning `null`.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case final Subclass value:
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _LabeledValue value)?  $default,){
final _that = this;
switch (_that) {
case _LabeledValue() when $default != null:
return $default(_that);case _:
  return null;

}
}
/// A variant of `when` that fallback to an `orElse` callback.
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return orElse();
/// }
/// ```

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( String value,  String label)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _LabeledValue() when $default != null:
return $default(_that.value,_that.label);case _:
  return orElse();

}
}
/// A `switch`-like method, using callbacks.
///
/// As opposed to `map`, this offers destructuring.
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case Subclass2(:final field2):
///     return ...;
/// }
/// ```

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( String value,  String label)  $default,) {final _that = this;
switch (_that) {
case _LabeledValue():
return $default(_that.value,_that.label);case _:
  throw StateError('Unexpected subclass');

}
}
/// A variant of `when` that fallback to returning `null`
///
/// It is equivalent to doing:
/// ```dart
/// switch (sealedClass) {
///   case Subclass(:final field):
///     return ...;
///   case _:
///     return null;
/// }
/// ```

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( String value,  String label)?  $default,) {final _that = this;
switch (_that) {
case _LabeledValue() when $default != null:
return $default(_that.value,_that.label);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _LabeledValue implements LabeledValue {
  const _LabeledValue({required this.value, required this.label});
  factory _LabeledValue.fromJson(Map<String, dynamic> json) => _$LabeledValueFromJson(json);

@override final  String value;
@override final  String label;

/// Create a copy of LabeledValue
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$LabeledValueCopyWith<_LabeledValue> get copyWith => __$LabeledValueCopyWithImpl<_LabeledValue>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _LabeledValue&&(identical(other.value, value) || other.value == value)&&(identical(other.label, label) || other.label == label));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,value,label);
}

@override
String toString() {
    return 'LabeledValue(value: $value, label: $label)';
}


}

/// @nodoc
abstract mixin class _$LabeledValueCopyWith<$Res> implements $LabeledValueCopyWith<$Res> {
  factory _$LabeledValueCopyWith(_LabeledValue value, $Res Function(_LabeledValue) _then) = __$LabeledValueCopyWithImpl;
@override @useResult
$Res call({
 String value, String label
});




}
/// @nodoc
class __$LabeledValueCopyWithImpl<$Res>
    implements _$LabeledValueCopyWith<$Res> {
  __$LabeledValueCopyWithImpl(this._self, this._then);

  final _LabeledValue _self;
  final $Res Function(_LabeledValue) _then;

/// Create a copy of LabeledValue
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? value = null,Object? label = null,}) {
  return _then(_LabeledValue(
value: null == value ? _self.value : value // ignore: cast_nullable_to_non_nullable
as String,label: null == label ? _self.label : label // ignore: cast_nullable_to_non_nullable
as String,
  ));
}


}

// dart format on
