// GENERATED CODE - DO NOT MODIFY BY HAND
// coverage:ignore-file
// ignore_for_file: type=lint, type=warning, deprecated_member_use, deprecated_member_use_from_same_package
// ignore_for_file: unused_element, deprecated_member_use, deprecated_member_use_from_same_package, use_function_type_syntax_for_parameters, unnecessary_const, avoid_init_to_null, invalid_override_different_default_values_named, prefer_expression_function_bodies, annotate_overrides, invalid_annotation_target, unnecessary_question_mark

part of 'job_posting.dart';

// **************************************************************************
// FreezedGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// dart format off
T _$identity<T>(T value) => value;

/// @nodoc
mixin _$JobLocation {

 int get id; String get name;
/// Create a copy of JobLocation
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$JobLocationCopyWith<JobLocation> get copyWith => _$JobLocationCopyWithImpl<JobLocation>(this as JobLocation, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as JobLocation;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is JobLocation&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.name, _this.name) || other.name == _this.name));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as JobLocation;
  return Object.hash(runtimeType,_this.id,_this.name);
}

@override
String toString() {
  final _this = this as JobLocation;
  return 'JobLocation(id: ${_this.id}, name: ${_this.name})';
}


}

/// @nodoc
abstract mixin class $JobLocationCopyWith<$Res>  {
  factory $JobLocationCopyWith(JobLocation value, $Res Function(JobLocation) _then) = _$JobLocationCopyWithImpl;
@useResult
$Res call({
 int id, String name
});




}
/// @nodoc
class _$JobLocationCopyWithImpl<$Res>
    implements $JobLocationCopyWith<$Res> {
  _$JobLocationCopyWithImpl(this._self, this._then);

  final JobLocation _self;
  final $Res Function(JobLocation) _then;

/// Create a copy of JobLocation
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? name = null,}) {
  return _then(JobLocation(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,
  ));
}

}


/// Adds pattern-matching-related methods to [JobLocation].
extension JobLocationPatterns on JobLocation {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _JobLocation value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _JobLocation() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _JobLocation value)  $default,){
final _that = this;
switch (_that) {
case _JobLocation():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _JobLocation value)?  $default,){
final _that = this;
switch (_that) {
case _JobLocation() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  String name)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _JobLocation() when $default != null:
return $default(_that.id,_that.name);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  String name)  $default,) {final _that = this;
switch (_that) {
case _JobLocation():
return $default(_that.id,_that.name);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  String name)?  $default,) {final _that = this;
switch (_that) {
case _JobLocation() when $default != null:
return $default(_that.id,_that.name);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _JobLocation implements JobLocation {
  const _JobLocation({required this.id, required this.name});
  factory _JobLocation.fromJson(Map<String, dynamic> json) => _$JobLocationFromJson(json);

@override final  int id;
@override final  String name;

/// Create a copy of JobLocation
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$JobLocationCopyWith<_JobLocation> get copyWith => __$JobLocationCopyWithImpl<_JobLocation>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _JobLocation&&(identical(other.id, id) || other.id == id)&&(identical(other.name, name) || other.name == name));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,name);
}

@override
String toString() {
    return 'JobLocation(id: $id, name: $name)';
}


}

/// @nodoc
abstract mixin class _$JobLocationCopyWith<$Res> implements $JobLocationCopyWith<$Res> {
  factory _$JobLocationCopyWith(_JobLocation value, $Res Function(_JobLocation) _then) = __$JobLocationCopyWithImpl;
@override @useResult
$Res call({
 int id, String name
});




}
/// @nodoc
class __$JobLocationCopyWithImpl<$Res>
    implements _$JobLocationCopyWith<$Res> {
  __$JobLocationCopyWithImpl(this._self, this._then);

  final _JobLocation _self;
  final $Res Function(_JobLocation) _then;

/// Create a copy of JobLocation
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? name = null,}) {
  return _then(_JobLocation(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,
  ));
}


}


/// @nodoc
mixin _$JobPosting {

 int get id; JobLocation get location; String get jobTitle; DateTime get workDate; String get startTime; String get endTime; int get hourlyWage; int get transportAllowance; int get capacity; int get remaining;
/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$JobPostingCopyWith<JobPosting> get copyWith => _$JobPostingCopyWithImpl<JobPosting>(this as JobPosting, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as JobPosting;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is JobPosting&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.location, _this.location) || other.location == _this.location)&&(identical(other.jobTitle, _this.jobTitle) || other.jobTitle == _this.jobTitle)&&(identical(other.workDate, _this.workDate) || other.workDate == _this.workDate)&&(identical(other.startTime, _this.startTime) || other.startTime == _this.startTime)&&(identical(other.endTime, _this.endTime) || other.endTime == _this.endTime)&&(identical(other.hourlyWage, _this.hourlyWage) || other.hourlyWage == _this.hourlyWage)&&(identical(other.transportAllowance, _this.transportAllowance) || other.transportAllowance == _this.transportAllowance)&&(identical(other.capacity, _this.capacity) || other.capacity == _this.capacity)&&(identical(other.remaining, _this.remaining) || other.remaining == _this.remaining));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as JobPosting;
  return Object.hash(runtimeType,_this.id,_this.location,_this.jobTitle,_this.workDate,_this.startTime,_this.endTime,_this.hourlyWage,_this.transportAllowance,_this.capacity,_this.remaining);
}

@override
String toString() {
  final _this = this as JobPosting;
  return 'JobPosting(id: ${_this.id}, location: ${_this.location}, jobTitle: ${_this.jobTitle}, workDate: ${_this.workDate}, startTime: ${_this.startTime}, endTime: ${_this.endTime}, hourlyWage: ${_this.hourlyWage}, transportAllowance: ${_this.transportAllowance}, capacity: ${_this.capacity}, remaining: ${_this.remaining})';
}


}

/// @nodoc
abstract mixin class $JobPostingCopyWith<$Res>  {
  factory $JobPostingCopyWith(JobPosting value, $Res Function(JobPosting) _then) = _$JobPostingCopyWithImpl;
@useResult
$Res call({
 int id, JobLocation location, String jobTitle, DateTime workDate, String startTime, String endTime, int hourlyWage, int transportAllowance, int capacity, int remaining
});


$JobLocationCopyWith<$Res> get location;

}
/// @nodoc
class _$JobPostingCopyWithImpl<$Res>
    implements $JobPostingCopyWith<$Res> {
  _$JobPostingCopyWithImpl(this._self, this._then);

  final JobPosting _self;
  final $Res Function(JobPosting) _then;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? location = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? hourlyWage = null,Object? transportAllowance = null,Object? capacity = null,Object? remaining = null,}) {
  return _then(JobPosting(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,location: null == location ? _self.location : location // ignore: cast_nullable_to_non_nullable
as JobLocation,jobTitle: null == jobTitle ? _self.jobTitle : jobTitle // ignore: cast_nullable_to_non_nullable
as String,workDate: null == workDate ? _self.workDate : workDate // ignore: cast_nullable_to_non_nullable
as DateTime,startTime: null == startTime ? _self.startTime : startTime // ignore: cast_nullable_to_non_nullable
as String,endTime: null == endTime ? _self.endTime : endTime // ignore: cast_nullable_to_non_nullable
as String,hourlyWage: null == hourlyWage ? _self.hourlyWage : hourlyWage // ignore: cast_nullable_to_non_nullable
as int,transportAllowance: null == transportAllowance ? _self.transportAllowance : transportAllowance // ignore: cast_nullable_to_non_nullable
as int,capacity: null == capacity ? _self.capacity : capacity // ignore: cast_nullable_to_non_nullable
as int,remaining: null == remaining ? _self.remaining : remaining // ignore: cast_nullable_to_non_nullable
as int,
  ));
}
/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobLocationCopyWith<$Res> get location {
  
  return $JobLocationCopyWith<$Res>(_self.location, (value) {
    return _then(_self.copyWith(location: value));
  });
}
}


/// Adds pattern-matching-related methods to [JobPosting].
extension JobPostingPatterns on JobPosting {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _JobPosting value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _JobPosting value)  $default,){
final _that = this;
switch (_that) {
case _JobPosting():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _JobPosting value)?  $default,){
final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining)  $default,) {final _that = this;
switch (_that) {
case _JobPosting():
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining)?  $default,) {final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _JobPosting implements JobPosting {
  const _JobPosting({required this.id, required this.location, required this.jobTitle, required this.workDate, required this.startTime, required this.endTime, required this.hourlyWage, required this.transportAllowance, required this.capacity, required this.remaining});
  factory _JobPosting.fromJson(Map<String, dynamic> json) => _$JobPostingFromJson(json);

@override final  int id;
@override final  JobLocation location;
@override final  String jobTitle;
@override final  DateTime workDate;
@override final  String startTime;
@override final  String endTime;
@override final  int hourlyWage;
@override final  int transportAllowance;
@override final  int capacity;
@override final  int remaining;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$JobPostingCopyWith<_JobPosting> get copyWith => __$JobPostingCopyWithImpl<_JobPosting>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _JobPosting&&(identical(other.id, id) || other.id == id)&&(identical(other.location, location) || other.location == location)&&(identical(other.jobTitle, jobTitle) || other.jobTitle == jobTitle)&&(identical(other.workDate, workDate) || other.workDate == workDate)&&(identical(other.startTime, startTime) || other.startTime == startTime)&&(identical(other.endTime, endTime) || other.endTime == endTime)&&(identical(other.hourlyWage, hourlyWage) || other.hourlyWage == hourlyWage)&&(identical(other.transportAllowance, transportAllowance) || other.transportAllowance == transportAllowance)&&(identical(other.capacity, capacity) || other.capacity == capacity)&&(identical(other.remaining, remaining) || other.remaining == remaining));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,location,jobTitle,workDate,startTime,endTime,hourlyWage,transportAllowance,capacity,remaining);
}

@override
String toString() {
    return 'JobPosting(id: $id, location: $location, jobTitle: $jobTitle, workDate: $workDate, startTime: $startTime, endTime: $endTime, hourlyWage: $hourlyWage, transportAllowance: $transportAllowance, capacity: $capacity, remaining: $remaining)';
}


}

/// @nodoc
abstract mixin class _$JobPostingCopyWith<$Res> implements $JobPostingCopyWith<$Res> {
  factory _$JobPostingCopyWith(_JobPosting value, $Res Function(_JobPosting) _then) = __$JobPostingCopyWithImpl;
@override @useResult
$Res call({
 int id, JobLocation location, String jobTitle, DateTime workDate, String startTime, String endTime, int hourlyWage, int transportAllowance, int capacity, int remaining
});


@override $JobLocationCopyWith<$Res> get location;

}
/// @nodoc
class __$JobPostingCopyWithImpl<$Res>
    implements _$JobPostingCopyWith<$Res> {
  __$JobPostingCopyWithImpl(this._self, this._then);

  final _JobPosting _self;
  final $Res Function(_JobPosting) _then;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? location = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? hourlyWage = null,Object? transportAllowance = null,Object? capacity = null,Object? remaining = null,}) {
  return _then(_JobPosting(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,location: null == location ? _self.location : location // ignore: cast_nullable_to_non_nullable
as JobLocation,jobTitle: null == jobTitle ? _self.jobTitle : jobTitle // ignore: cast_nullable_to_non_nullable
as String,workDate: null == workDate ? _self.workDate : workDate // ignore: cast_nullable_to_non_nullable
as DateTime,startTime: null == startTime ? _self.startTime : startTime // ignore: cast_nullable_to_non_nullable
as String,endTime: null == endTime ? _self.endTime : endTime // ignore: cast_nullable_to_non_nullable
as String,hourlyWage: null == hourlyWage ? _self.hourlyWage : hourlyWage // ignore: cast_nullable_to_non_nullable
as int,transportAllowance: null == transportAllowance ? _self.transportAllowance : transportAllowance // ignore: cast_nullable_to_non_nullable
as int,capacity: null == capacity ? _self.capacity : capacity // ignore: cast_nullable_to_non_nullable
as int,remaining: null == remaining ? _self.remaining : remaining // ignore: cast_nullable_to_non_nullable
as int,
  ));
}

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobLocationCopyWith<$Res> get location {
  
  return $JobLocationCopyWith<$Res>(_self.location, (value) {
    return _then(_self.copyWith(location: value));
  });
}
}

// dart format on
