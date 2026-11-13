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

 int get id; JobLocation get location; String get jobTitle; DateTime get workDate; String get startTime; String get endTime; int get hourlyWage; int get transportAllowance; int get capacity; int get remaining; LabeledValue get status; MyApplication? get myApplication; JobPostingCan get can;
/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$JobPostingCopyWith<JobPosting> get copyWith => _$JobPostingCopyWithImpl<JobPosting>(this as JobPosting, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as JobPosting;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is JobPosting&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.location, _this.location) || other.location == _this.location)&&(identical(other.jobTitle, _this.jobTitle) || other.jobTitle == _this.jobTitle)&&(identical(other.workDate, _this.workDate) || other.workDate == _this.workDate)&&(identical(other.startTime, _this.startTime) || other.startTime == _this.startTime)&&(identical(other.endTime, _this.endTime) || other.endTime == _this.endTime)&&(identical(other.hourlyWage, _this.hourlyWage) || other.hourlyWage == _this.hourlyWage)&&(identical(other.transportAllowance, _this.transportAllowance) || other.transportAllowance == _this.transportAllowance)&&(identical(other.capacity, _this.capacity) || other.capacity == _this.capacity)&&(identical(other.remaining, _this.remaining) || other.remaining == _this.remaining)&&(identical(other.status, _this.status) || other.status == _this.status)&&(identical(other.myApplication, _this.myApplication) || other.myApplication == _this.myApplication)&&(identical(other.can, _this.can) || other.can == _this.can));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as JobPosting;
  return Object.hash(runtimeType,_this.id,_this.location,_this.jobTitle,_this.workDate,_this.startTime,_this.endTime,_this.hourlyWage,_this.transportAllowance,_this.capacity,_this.remaining,_this.status,_this.myApplication,_this.can);
}

@override
String toString() {
  final _this = this as JobPosting;
  return 'JobPosting(id: ${_this.id}, location: ${_this.location}, jobTitle: ${_this.jobTitle}, workDate: ${_this.workDate}, startTime: ${_this.startTime}, endTime: ${_this.endTime}, hourlyWage: ${_this.hourlyWage}, transportAllowance: ${_this.transportAllowance}, capacity: ${_this.capacity}, remaining: ${_this.remaining}, status: ${_this.status}, myApplication: ${_this.myApplication}, can: ${_this.can})';
}


}

/// @nodoc
abstract mixin class $JobPostingCopyWith<$Res>  {
  factory $JobPostingCopyWith(JobPosting value, $Res Function(JobPosting) _then) = _$JobPostingCopyWithImpl;
@useResult
$Res call({
 int id, JobLocation location, String jobTitle, DateTime workDate, String startTime, String endTime, int hourlyWage, int transportAllowance, int capacity, int remaining, LabeledValue status, MyApplication? myApplication, JobPostingCan can
});


$JobLocationCopyWith<$Res> get location;$LabeledValueCopyWith<$Res> get status;$MyApplicationCopyWith<$Res>? get myApplication;$JobPostingCanCopyWith<$Res> get can;

}
/// @nodoc
class _$JobPostingCopyWithImpl<$Res>
    implements $JobPostingCopyWith<$Res> {
  _$JobPostingCopyWithImpl(this._self, this._then);

  final JobPosting _self;
  final $Res Function(JobPosting) _then;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? location = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? hourlyWage = null,Object? transportAllowance = null,Object? capacity = null,Object? remaining = null,Object? status = null,Object? myApplication = freezed,Object? can = null,}) {
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
as int,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,myApplication: freezed == myApplication ? _self.myApplication : myApplication // ignore: cast_nullable_to_non_nullable
as MyApplication?,can: null == can ? _self.can : can // ignore: cast_nullable_to_non_nullable
as JobPostingCan,
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
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$MyApplicationCopyWith<$Res>? get myApplication {
    if (_self.myApplication == null) {
    return null;
  }

  return $MyApplicationCopyWith<$Res>(_self.myApplication!, (value) {
    return _then(_self.copyWith(myApplication: value));
  });
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobPostingCanCopyWith<$Res> get can {
  
  return $JobPostingCanCopyWith<$Res>(_self.can, (value) {
    return _then(_self.copyWith(can: value));
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining,  LabeledValue status,  MyApplication? myApplication,  JobPostingCan can)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining,_that.status,_that.myApplication,_that.can);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining,  LabeledValue status,  MyApplication? myApplication,  JobPostingCan can)  $default,) {final _that = this;
switch (_that) {
case _JobPosting():
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining,_that.status,_that.myApplication,_that.can);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  JobLocation location,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  int hourlyWage,  int transportAllowance,  int capacity,  int remaining,  LabeledValue status,  MyApplication? myApplication,  JobPostingCan can)?  $default,) {final _that = this;
switch (_that) {
case _JobPosting() when $default != null:
return $default(_that.id,_that.location,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.hourlyWage,_that.transportAllowance,_that.capacity,_that.remaining,_that.status,_that.myApplication,_that.can);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _JobPosting implements JobPosting {
  const _JobPosting({required this.id, required this.location, required this.jobTitle, required this.workDate, required this.startTime, required this.endTime, required this.hourlyWage, required this.transportAllowance, required this.capacity, required this.remaining, required this.status, required this.myApplication, required this.can});
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
@override final  LabeledValue status;
@override final  MyApplication? myApplication;
@override final  JobPostingCan can;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$JobPostingCopyWith<_JobPosting> get copyWith => __$JobPostingCopyWithImpl<_JobPosting>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _JobPosting&&(identical(other.id, id) || other.id == id)&&(identical(other.location, location) || other.location == location)&&(identical(other.jobTitle, jobTitle) || other.jobTitle == jobTitle)&&(identical(other.workDate, workDate) || other.workDate == workDate)&&(identical(other.startTime, startTime) || other.startTime == startTime)&&(identical(other.endTime, endTime) || other.endTime == endTime)&&(identical(other.hourlyWage, hourlyWage) || other.hourlyWage == hourlyWage)&&(identical(other.transportAllowance, transportAllowance) || other.transportAllowance == transportAllowance)&&(identical(other.capacity, capacity) || other.capacity == capacity)&&(identical(other.remaining, remaining) || other.remaining == remaining)&&(identical(other.status, status) || other.status == status)&&(identical(other.myApplication, myApplication) || other.myApplication == myApplication)&&(identical(other.can, can) || other.can == can));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,location,jobTitle,workDate,startTime,endTime,hourlyWage,transportAllowance,capacity,remaining,status,myApplication,can);
}

@override
String toString() {
    return 'JobPosting(id: $id, location: $location, jobTitle: $jobTitle, workDate: $workDate, startTime: $startTime, endTime: $endTime, hourlyWage: $hourlyWage, transportAllowance: $transportAllowance, capacity: $capacity, remaining: $remaining, status: $status, myApplication: $myApplication, can: $can)';
}


}

/// @nodoc
abstract mixin class _$JobPostingCopyWith<$Res> implements $JobPostingCopyWith<$Res> {
  factory _$JobPostingCopyWith(_JobPosting value, $Res Function(_JobPosting) _then) = __$JobPostingCopyWithImpl;
@override @useResult
$Res call({
 int id, JobLocation location, String jobTitle, DateTime workDate, String startTime, String endTime, int hourlyWage, int transportAllowance, int capacity, int remaining, LabeledValue status, MyApplication? myApplication, JobPostingCan can
});


@override $JobLocationCopyWith<$Res> get location;@override $LabeledValueCopyWith<$Res> get status;@override $MyApplicationCopyWith<$Res>? get myApplication;@override $JobPostingCanCopyWith<$Res> get can;

}
/// @nodoc
class __$JobPostingCopyWithImpl<$Res>
    implements _$JobPostingCopyWith<$Res> {
  __$JobPostingCopyWithImpl(this._self, this._then);

  final _JobPosting _self;
  final $Res Function(_JobPosting) _then;

/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? location = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? hourlyWage = null,Object? transportAllowance = null,Object? capacity = null,Object? remaining = null,Object? status = null,Object? myApplication = freezed,Object? can = null,}) {
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
as int,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,myApplication: freezed == myApplication ? _self.myApplication : myApplication // ignore: cast_nullable_to_non_nullable
as MyApplication?,can: null == can ? _self.can : can // ignore: cast_nullable_to_non_nullable
as JobPostingCan,
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
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$MyApplicationCopyWith<$Res>? get myApplication {
    if (_self.myApplication == null) {
    return null;
  }

  return $MyApplicationCopyWith<$Res>(_self.myApplication!, (value) {
    return _then(_self.copyWith(myApplication: value));
  });
}/// Create a copy of JobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobPostingCanCopyWith<$Res> get can {
  
  return $JobPostingCanCopyWith<$Res>(_self.can, (value) {
    return _then(_self.copyWith(can: value));
  });
}
}


/// @nodoc
mixin _$MyApplication {

 int get id; LabeledValue get status;
/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$MyApplicationCopyWith<MyApplication> get copyWith => _$MyApplicationCopyWithImpl<MyApplication>(this as MyApplication, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as MyApplication;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is MyApplication&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.status, _this.status) || other.status == _this.status));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as MyApplication;
  return Object.hash(runtimeType,_this.id,_this.status);
}

@override
String toString() {
  final _this = this as MyApplication;
  return 'MyApplication(id: ${_this.id}, status: ${_this.status})';
}


}

/// @nodoc
abstract mixin class $MyApplicationCopyWith<$Res>  {
  factory $MyApplicationCopyWith(MyApplication value, $Res Function(MyApplication) _then) = _$MyApplicationCopyWithImpl;
@useResult
$Res call({
 int id, LabeledValue status
});


$LabeledValueCopyWith<$Res> get status;

}
/// @nodoc
class _$MyApplicationCopyWithImpl<$Res>
    implements $MyApplicationCopyWith<$Res> {
  _$MyApplicationCopyWithImpl(this._self, this._then);

  final MyApplication _self;
  final $Res Function(MyApplication) _then;

/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? status = null,}) {
  return _then(MyApplication(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,
  ));
}
/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}
}


/// Adds pattern-matching-related methods to [MyApplication].
extension MyApplicationPatterns on MyApplication {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _MyApplication value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _MyApplication() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _MyApplication value)  $default,){
final _that = this;
switch (_that) {
case _MyApplication():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _MyApplication value)?  $default,){
final _that = this;
switch (_that) {
case _MyApplication() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  LabeledValue status)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _MyApplication() when $default != null:
return $default(_that.id,_that.status);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  LabeledValue status)  $default,) {final _that = this;
switch (_that) {
case _MyApplication():
return $default(_that.id,_that.status);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  LabeledValue status)?  $default,) {final _that = this;
switch (_that) {
case _MyApplication() when $default != null:
return $default(_that.id,_that.status);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _MyApplication implements MyApplication {
  const _MyApplication({required this.id, required this.status});
  factory _MyApplication.fromJson(Map<String, dynamic> json) => _$MyApplicationFromJson(json);

@override final  int id;
@override final  LabeledValue status;

/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$MyApplicationCopyWith<_MyApplication> get copyWith => __$MyApplicationCopyWithImpl<_MyApplication>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _MyApplication&&(identical(other.id, id) || other.id == id)&&(identical(other.status, status) || other.status == status));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,status);
}

@override
String toString() {
    return 'MyApplication(id: $id, status: $status)';
}


}

/// @nodoc
abstract mixin class _$MyApplicationCopyWith<$Res> implements $MyApplicationCopyWith<$Res> {
  factory _$MyApplicationCopyWith(_MyApplication value, $Res Function(_MyApplication) _then) = __$MyApplicationCopyWithImpl;
@override @useResult
$Res call({
 int id, LabeledValue status
});


@override $LabeledValueCopyWith<$Res> get status;

}
/// @nodoc
class __$MyApplicationCopyWithImpl<$Res>
    implements _$MyApplicationCopyWith<$Res> {
  __$MyApplicationCopyWithImpl(this._self, this._then);

  final _MyApplication _self;
  final $Res Function(_MyApplication) _then;

/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? status = null,}) {
  return _then(_MyApplication(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,
  ));
}

/// Create a copy of MyApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}
}


/// @nodoc
mixin _$JobPostingCan {

 bool get apply;
/// Create a copy of JobPostingCan
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$JobPostingCanCopyWith<JobPostingCan> get copyWith => _$JobPostingCanCopyWithImpl<JobPostingCan>(this as JobPostingCan, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as JobPostingCan;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is JobPostingCan&&(identical(other.apply, _this.apply) || other.apply == _this.apply));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as JobPostingCan;
  return Object.hash(runtimeType,_this.apply);
}

@override
String toString() {
  final _this = this as JobPostingCan;
  return 'JobPostingCan(apply: ${_this.apply})';
}


}

/// @nodoc
abstract mixin class $JobPostingCanCopyWith<$Res>  {
  factory $JobPostingCanCopyWith(JobPostingCan value, $Res Function(JobPostingCan) _then) = _$JobPostingCanCopyWithImpl;
@useResult
$Res call({
 bool apply
});




}
/// @nodoc
class _$JobPostingCanCopyWithImpl<$Res>
    implements $JobPostingCanCopyWith<$Res> {
  _$JobPostingCanCopyWithImpl(this._self, this._then);

  final JobPostingCan _self;
  final $Res Function(JobPostingCan) _then;

/// Create a copy of JobPostingCan
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? apply = null,}) {
  return _then(JobPostingCan(
apply: null == apply ? _self.apply : apply // ignore: cast_nullable_to_non_nullable
as bool,
  ));
}

}


/// Adds pattern-matching-related methods to [JobPostingCan].
extension JobPostingCanPatterns on JobPostingCan {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _JobPostingCan value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _JobPostingCan() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _JobPostingCan value)  $default,){
final _that = this;
switch (_that) {
case _JobPostingCan():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _JobPostingCan value)?  $default,){
final _that = this;
switch (_that) {
case _JobPostingCan() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( bool apply)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _JobPostingCan() when $default != null:
return $default(_that.apply);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( bool apply)  $default,) {final _that = this;
switch (_that) {
case _JobPostingCan():
return $default(_that.apply);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( bool apply)?  $default,) {final _that = this;
switch (_that) {
case _JobPostingCan() when $default != null:
return $default(_that.apply);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _JobPostingCan implements JobPostingCan {
  const _JobPostingCan({required this.apply});
  factory _JobPostingCan.fromJson(Map<String, dynamic> json) => _$JobPostingCanFromJson(json);

@override final  bool apply;

/// Create a copy of JobPostingCan
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$JobPostingCanCopyWith<_JobPostingCan> get copyWith => __$JobPostingCanCopyWithImpl<_JobPostingCan>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _JobPostingCan&&(identical(other.apply, apply) || other.apply == apply));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,apply);
}

@override
String toString() {
    return 'JobPostingCan(apply: $apply)';
}


}

/// @nodoc
abstract mixin class _$JobPostingCanCopyWith<$Res> implements $JobPostingCanCopyWith<$Res> {
  factory _$JobPostingCanCopyWith(_JobPostingCan value, $Res Function(_JobPostingCan) _then) = __$JobPostingCanCopyWithImpl;
@override @useResult
$Res call({
 bool apply
});




}
/// @nodoc
class __$JobPostingCanCopyWithImpl<$Res>
    implements _$JobPostingCanCopyWith<$Res> {
  __$JobPostingCanCopyWithImpl(this._self, this._then);

  final _JobPostingCan _self;
  final $Res Function(_JobPostingCan) _then;

/// Create a copy of JobPostingCan
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? apply = null,}) {
  return _then(_JobPostingCan(
apply: null == apply ? _self.apply : apply // ignore: cast_nullable_to_non_nullable
as bool,
  ));
}


}

// dart format on
