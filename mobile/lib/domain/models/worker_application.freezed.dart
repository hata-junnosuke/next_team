// GENERATED CODE - DO NOT MODIFY BY HAND
// coverage:ignore-file
// ignore_for_file: type=lint, type=warning, deprecated_member_use, deprecated_member_use_from_same_package
// ignore_for_file: unused_element, deprecated_member_use, deprecated_member_use_from_same_package, use_function_type_syntax_for_parameters, unnecessary_const, avoid_init_to_null, invalid_override_different_default_values_named, prefer_expression_function_bodies, annotate_overrides, invalid_annotation_target, unnecessary_question_mark

part of 'worker_application.dart';

// **************************************************************************
// FreezedGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// dart format off
T _$identity<T>(T value) => value;

/// @nodoc
mixin _$WorkerApplication {

 int get id; ApplicationJobPosting get jobPosting; LabeledValue get status; DateTime get appliedAt; DateTime? get confirmedAt; int? get confirmedHourlyWage; int? get confirmedTransportAllowance; LabeledValue? get cancelReason; DateTime? get cancelledAt; WorkerApplicationCan get can;
/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$WorkerApplicationCopyWith<WorkerApplication> get copyWith => _$WorkerApplicationCopyWithImpl<WorkerApplication>(this as WorkerApplication, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as WorkerApplication;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is WorkerApplication&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.jobPosting, _this.jobPosting) || other.jobPosting == _this.jobPosting)&&(identical(other.status, _this.status) || other.status == _this.status)&&(identical(other.appliedAt, _this.appliedAt) || other.appliedAt == _this.appliedAt)&&(identical(other.confirmedAt, _this.confirmedAt) || other.confirmedAt == _this.confirmedAt)&&(identical(other.confirmedHourlyWage, _this.confirmedHourlyWage) || other.confirmedHourlyWage == _this.confirmedHourlyWage)&&(identical(other.confirmedTransportAllowance, _this.confirmedTransportAllowance) || other.confirmedTransportAllowance == _this.confirmedTransportAllowance)&&(identical(other.cancelReason, _this.cancelReason) || other.cancelReason == _this.cancelReason)&&(identical(other.cancelledAt, _this.cancelledAt) || other.cancelledAt == _this.cancelledAt)&&(identical(other.can, _this.can) || other.can == _this.can));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as WorkerApplication;
  return Object.hash(runtimeType,_this.id,_this.jobPosting,_this.status,_this.appliedAt,_this.confirmedAt,_this.confirmedHourlyWage,_this.confirmedTransportAllowance,_this.cancelReason,_this.cancelledAt,_this.can);
}

@override
String toString() {
  final _this = this as WorkerApplication;
  return 'WorkerApplication(id: ${_this.id}, jobPosting: ${_this.jobPosting}, status: ${_this.status}, appliedAt: ${_this.appliedAt}, confirmedAt: ${_this.confirmedAt}, confirmedHourlyWage: ${_this.confirmedHourlyWage}, confirmedTransportAllowance: ${_this.confirmedTransportAllowance}, cancelReason: ${_this.cancelReason}, cancelledAt: ${_this.cancelledAt}, can: ${_this.can})';
}


}

/// @nodoc
abstract mixin class $WorkerApplicationCopyWith<$Res>  {
  factory $WorkerApplicationCopyWith(WorkerApplication value, $Res Function(WorkerApplication) _then) = _$WorkerApplicationCopyWithImpl;
@useResult
$Res call({
 int id, ApplicationJobPosting jobPosting, LabeledValue status, DateTime appliedAt, DateTime? confirmedAt, int? confirmedHourlyWage, int? confirmedTransportAllowance, LabeledValue? cancelReason, DateTime? cancelledAt, WorkerApplicationCan can
});


$ApplicationJobPostingCopyWith<$Res> get jobPosting;$LabeledValueCopyWith<$Res> get status;$LabeledValueCopyWith<$Res>? get cancelReason;$WorkerApplicationCanCopyWith<$Res> get can;

}
/// @nodoc
class _$WorkerApplicationCopyWithImpl<$Res>
    implements $WorkerApplicationCopyWith<$Res> {
  _$WorkerApplicationCopyWithImpl(this._self, this._then);

  final WorkerApplication _self;
  final $Res Function(WorkerApplication) _then;

/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? jobPosting = null,Object? status = null,Object? appliedAt = null,Object? confirmedAt = freezed,Object? confirmedHourlyWage = freezed,Object? confirmedTransportAllowance = freezed,Object? cancelReason = freezed,Object? cancelledAt = freezed,Object? can = null,}) {
  return _then(WorkerApplication(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,jobPosting: null == jobPosting ? _self.jobPosting : jobPosting // ignore: cast_nullable_to_non_nullable
as ApplicationJobPosting,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,appliedAt: null == appliedAt ? _self.appliedAt : appliedAt // ignore: cast_nullable_to_non_nullable
as DateTime,confirmedAt: freezed == confirmedAt ? _self.confirmedAt : confirmedAt // ignore: cast_nullable_to_non_nullable
as DateTime?,confirmedHourlyWage: freezed == confirmedHourlyWage ? _self.confirmedHourlyWage : confirmedHourlyWage // ignore: cast_nullable_to_non_nullable
as int?,confirmedTransportAllowance: freezed == confirmedTransportAllowance ? _self.confirmedTransportAllowance : confirmedTransportAllowance // ignore: cast_nullable_to_non_nullable
as int?,cancelReason: freezed == cancelReason ? _self.cancelReason : cancelReason // ignore: cast_nullable_to_non_nullable
as LabeledValue?,cancelledAt: freezed == cancelledAt ? _self.cancelledAt : cancelledAt // ignore: cast_nullable_to_non_nullable
as DateTime?,can: null == can ? _self.can : can // ignore: cast_nullable_to_non_nullable
as WorkerApplicationCan,
  ));
}
/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$ApplicationJobPostingCopyWith<$Res> get jobPosting {
  
  return $ApplicationJobPostingCopyWith<$Res>(_self.jobPosting, (value) {
    return _then(_self.copyWith(jobPosting: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res>? get cancelReason {
    if (_self.cancelReason == null) {
    return null;
  }

  return $LabeledValueCopyWith<$Res>(_self.cancelReason!, (value) {
    return _then(_self.copyWith(cancelReason: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$WorkerApplicationCanCopyWith<$Res> get can {
  
  return $WorkerApplicationCanCopyWith<$Res>(_self.can, (value) {
    return _then(_self.copyWith(can: value));
  });
}
}


/// Adds pattern-matching-related methods to [WorkerApplication].
extension WorkerApplicationPatterns on WorkerApplication {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _WorkerApplication value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _WorkerApplication() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _WorkerApplication value)  $default,){
final _that = this;
switch (_that) {
case _WorkerApplication():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _WorkerApplication value)?  $default,){
final _that = this;
switch (_that) {
case _WorkerApplication() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  ApplicationJobPosting jobPosting,  LabeledValue status,  DateTime appliedAt,  DateTime? confirmedAt,  int? confirmedHourlyWage,  int? confirmedTransportAllowance,  LabeledValue? cancelReason,  DateTime? cancelledAt,  WorkerApplicationCan can)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _WorkerApplication() when $default != null:
return $default(_that.id,_that.jobPosting,_that.status,_that.appliedAt,_that.confirmedAt,_that.confirmedHourlyWage,_that.confirmedTransportAllowance,_that.cancelReason,_that.cancelledAt,_that.can);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  ApplicationJobPosting jobPosting,  LabeledValue status,  DateTime appliedAt,  DateTime? confirmedAt,  int? confirmedHourlyWage,  int? confirmedTransportAllowance,  LabeledValue? cancelReason,  DateTime? cancelledAt,  WorkerApplicationCan can)  $default,) {final _that = this;
switch (_that) {
case _WorkerApplication():
return $default(_that.id,_that.jobPosting,_that.status,_that.appliedAt,_that.confirmedAt,_that.confirmedHourlyWage,_that.confirmedTransportAllowance,_that.cancelReason,_that.cancelledAt,_that.can);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  ApplicationJobPosting jobPosting,  LabeledValue status,  DateTime appliedAt,  DateTime? confirmedAt,  int? confirmedHourlyWage,  int? confirmedTransportAllowance,  LabeledValue? cancelReason,  DateTime? cancelledAt,  WorkerApplicationCan can)?  $default,) {final _that = this;
switch (_that) {
case _WorkerApplication() when $default != null:
return $default(_that.id,_that.jobPosting,_that.status,_that.appliedAt,_that.confirmedAt,_that.confirmedHourlyWage,_that.confirmedTransportAllowance,_that.cancelReason,_that.cancelledAt,_that.can);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _WorkerApplication implements WorkerApplication {
  const _WorkerApplication({required this.id, required this.jobPosting, required this.status, required this.appliedAt, required this.confirmedAt, required this.confirmedHourlyWage, required this.confirmedTransportAllowance, required this.cancelReason, required this.cancelledAt, required this.can});
  factory _WorkerApplication.fromJson(Map<String, dynamic> json) => _$WorkerApplicationFromJson(json);

@override final  int id;
@override final  ApplicationJobPosting jobPosting;
@override final  LabeledValue status;
@override final  DateTime appliedAt;
@override final  DateTime? confirmedAt;
@override final  int? confirmedHourlyWage;
@override final  int? confirmedTransportAllowance;
@override final  LabeledValue? cancelReason;
@override final  DateTime? cancelledAt;
@override final  WorkerApplicationCan can;

/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$WorkerApplicationCopyWith<_WorkerApplication> get copyWith => __$WorkerApplicationCopyWithImpl<_WorkerApplication>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _WorkerApplication&&(identical(other.id, id) || other.id == id)&&(identical(other.jobPosting, jobPosting) || other.jobPosting == jobPosting)&&(identical(other.status, status) || other.status == status)&&(identical(other.appliedAt, appliedAt) || other.appliedAt == appliedAt)&&(identical(other.confirmedAt, confirmedAt) || other.confirmedAt == confirmedAt)&&(identical(other.confirmedHourlyWage, confirmedHourlyWage) || other.confirmedHourlyWage == confirmedHourlyWage)&&(identical(other.confirmedTransportAllowance, confirmedTransportAllowance) || other.confirmedTransportAllowance == confirmedTransportAllowance)&&(identical(other.cancelReason, cancelReason) || other.cancelReason == cancelReason)&&(identical(other.cancelledAt, cancelledAt) || other.cancelledAt == cancelledAt)&&(identical(other.can, can) || other.can == can));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,jobPosting,status,appliedAt,confirmedAt,confirmedHourlyWage,confirmedTransportAllowance,cancelReason,cancelledAt,can);
}

@override
String toString() {
    return 'WorkerApplication(id: $id, jobPosting: $jobPosting, status: $status, appliedAt: $appliedAt, confirmedAt: $confirmedAt, confirmedHourlyWage: $confirmedHourlyWage, confirmedTransportAllowance: $confirmedTransportAllowance, cancelReason: $cancelReason, cancelledAt: $cancelledAt, can: $can)';
}


}

/// @nodoc
abstract mixin class _$WorkerApplicationCopyWith<$Res> implements $WorkerApplicationCopyWith<$Res> {
  factory _$WorkerApplicationCopyWith(_WorkerApplication value, $Res Function(_WorkerApplication) _then) = __$WorkerApplicationCopyWithImpl;
@override @useResult
$Res call({
 int id, ApplicationJobPosting jobPosting, LabeledValue status, DateTime appliedAt, DateTime? confirmedAt, int? confirmedHourlyWage, int? confirmedTransportAllowance, LabeledValue? cancelReason, DateTime? cancelledAt, WorkerApplicationCan can
});


@override $ApplicationJobPostingCopyWith<$Res> get jobPosting;@override $LabeledValueCopyWith<$Res> get status;@override $LabeledValueCopyWith<$Res>? get cancelReason;@override $WorkerApplicationCanCopyWith<$Res> get can;

}
/// @nodoc
class __$WorkerApplicationCopyWithImpl<$Res>
    implements _$WorkerApplicationCopyWith<$Res> {
  __$WorkerApplicationCopyWithImpl(this._self, this._then);

  final _WorkerApplication _self;
  final $Res Function(_WorkerApplication) _then;

/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? jobPosting = null,Object? status = null,Object? appliedAt = null,Object? confirmedAt = freezed,Object? confirmedHourlyWage = freezed,Object? confirmedTransportAllowance = freezed,Object? cancelReason = freezed,Object? cancelledAt = freezed,Object? can = null,}) {
  return _then(_WorkerApplication(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,jobPosting: null == jobPosting ? _self.jobPosting : jobPosting // ignore: cast_nullable_to_non_nullable
as ApplicationJobPosting,status: null == status ? _self.status : status // ignore: cast_nullable_to_non_nullable
as LabeledValue,appliedAt: null == appliedAt ? _self.appliedAt : appliedAt // ignore: cast_nullable_to_non_nullable
as DateTime,confirmedAt: freezed == confirmedAt ? _self.confirmedAt : confirmedAt // ignore: cast_nullable_to_non_nullable
as DateTime?,confirmedHourlyWage: freezed == confirmedHourlyWage ? _self.confirmedHourlyWage : confirmedHourlyWage // ignore: cast_nullable_to_non_nullable
as int?,confirmedTransportAllowance: freezed == confirmedTransportAllowance ? _self.confirmedTransportAllowance : confirmedTransportAllowance // ignore: cast_nullable_to_non_nullable
as int?,cancelReason: freezed == cancelReason ? _self.cancelReason : cancelReason // ignore: cast_nullable_to_non_nullable
as LabeledValue?,cancelledAt: freezed == cancelledAt ? _self.cancelledAt : cancelledAt // ignore: cast_nullable_to_non_nullable
as DateTime?,can: null == can ? _self.can : can // ignore: cast_nullable_to_non_nullable
as WorkerApplicationCan,
  ));
}

/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$ApplicationJobPostingCopyWith<$Res> get jobPosting {
  
  return $ApplicationJobPostingCopyWith<$Res>(_self.jobPosting, (value) {
    return _then(_self.copyWith(jobPosting: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res> get status {
  
  return $LabeledValueCopyWith<$Res>(_self.status, (value) {
    return _then(_self.copyWith(status: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$LabeledValueCopyWith<$Res>? get cancelReason {
    if (_self.cancelReason == null) {
    return null;
  }

  return $LabeledValueCopyWith<$Res>(_self.cancelReason!, (value) {
    return _then(_self.copyWith(cancelReason: value));
  });
}/// Create a copy of WorkerApplication
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$WorkerApplicationCanCopyWith<$Res> get can {
  
  return $WorkerApplicationCanCopyWith<$Res>(_self.can, (value) {
    return _then(_self.copyWith(can: value));
  });
}
}


/// @nodoc
mixin _$ApplicationJobPosting {

 int get id; String get jobTitle; DateTime get workDate; String get startTime; String get endTime; JobLocation get location;
/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$ApplicationJobPostingCopyWith<ApplicationJobPosting> get copyWith => _$ApplicationJobPostingCopyWithImpl<ApplicationJobPosting>(this as ApplicationJobPosting, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as ApplicationJobPosting;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is ApplicationJobPosting&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.jobTitle, _this.jobTitle) || other.jobTitle == _this.jobTitle)&&(identical(other.workDate, _this.workDate) || other.workDate == _this.workDate)&&(identical(other.startTime, _this.startTime) || other.startTime == _this.startTime)&&(identical(other.endTime, _this.endTime) || other.endTime == _this.endTime)&&(identical(other.location, _this.location) || other.location == _this.location));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as ApplicationJobPosting;
  return Object.hash(runtimeType,_this.id,_this.jobTitle,_this.workDate,_this.startTime,_this.endTime,_this.location);
}

@override
String toString() {
  final _this = this as ApplicationJobPosting;
  return 'ApplicationJobPosting(id: ${_this.id}, jobTitle: ${_this.jobTitle}, workDate: ${_this.workDate}, startTime: ${_this.startTime}, endTime: ${_this.endTime}, location: ${_this.location})';
}


}

/// @nodoc
abstract mixin class $ApplicationJobPostingCopyWith<$Res>  {
  factory $ApplicationJobPostingCopyWith(ApplicationJobPosting value, $Res Function(ApplicationJobPosting) _then) = _$ApplicationJobPostingCopyWithImpl;
@useResult
$Res call({
 int id, String jobTitle, DateTime workDate, String startTime, String endTime, JobLocation location
});


$JobLocationCopyWith<$Res> get location;

}
/// @nodoc
class _$ApplicationJobPostingCopyWithImpl<$Res>
    implements $ApplicationJobPostingCopyWith<$Res> {
  _$ApplicationJobPostingCopyWithImpl(this._self, this._then);

  final ApplicationJobPosting _self;
  final $Res Function(ApplicationJobPosting) _then;

/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? location = null,}) {
  return _then(ApplicationJobPosting(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,jobTitle: null == jobTitle ? _self.jobTitle : jobTitle // ignore: cast_nullable_to_non_nullable
as String,workDate: null == workDate ? _self.workDate : workDate // ignore: cast_nullable_to_non_nullable
as DateTime,startTime: null == startTime ? _self.startTime : startTime // ignore: cast_nullable_to_non_nullable
as String,endTime: null == endTime ? _self.endTime : endTime // ignore: cast_nullable_to_non_nullable
as String,location: null == location ? _self.location : location // ignore: cast_nullable_to_non_nullable
as JobLocation,
  ));
}
/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobLocationCopyWith<$Res> get location {
  
  return $JobLocationCopyWith<$Res>(_self.location, (value) {
    return _then(_self.copyWith(location: value));
  });
}
}


/// Adds pattern-matching-related methods to [ApplicationJobPosting].
extension ApplicationJobPostingPatterns on ApplicationJobPosting {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _ApplicationJobPosting value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _ApplicationJobPosting() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _ApplicationJobPosting value)  $default,){
final _that = this;
switch (_that) {
case _ApplicationJobPosting():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _ApplicationJobPosting value)?  $default,){
final _that = this;
switch (_that) {
case _ApplicationJobPosting() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  JobLocation location)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _ApplicationJobPosting() when $default != null:
return $default(_that.id,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.location);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  JobLocation location)  $default,) {final _that = this;
switch (_that) {
case _ApplicationJobPosting():
return $default(_that.id,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.location);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  String jobTitle,  DateTime workDate,  String startTime,  String endTime,  JobLocation location)?  $default,) {final _that = this;
switch (_that) {
case _ApplicationJobPosting() when $default != null:
return $default(_that.id,_that.jobTitle,_that.workDate,_that.startTime,_that.endTime,_that.location);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _ApplicationJobPosting implements ApplicationJobPosting {
  const _ApplicationJobPosting({required this.id, required this.jobTitle, required this.workDate, required this.startTime, required this.endTime, required this.location});
  factory _ApplicationJobPosting.fromJson(Map<String, dynamic> json) => _$ApplicationJobPostingFromJson(json);

@override final  int id;
@override final  String jobTitle;
@override final  DateTime workDate;
@override final  String startTime;
@override final  String endTime;
@override final  JobLocation location;

/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$ApplicationJobPostingCopyWith<_ApplicationJobPosting> get copyWith => __$ApplicationJobPostingCopyWithImpl<_ApplicationJobPosting>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _ApplicationJobPosting&&(identical(other.id, id) || other.id == id)&&(identical(other.jobTitle, jobTitle) || other.jobTitle == jobTitle)&&(identical(other.workDate, workDate) || other.workDate == workDate)&&(identical(other.startTime, startTime) || other.startTime == startTime)&&(identical(other.endTime, endTime) || other.endTime == endTime)&&(identical(other.location, location) || other.location == location));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,jobTitle,workDate,startTime,endTime,location);
}

@override
String toString() {
    return 'ApplicationJobPosting(id: $id, jobTitle: $jobTitle, workDate: $workDate, startTime: $startTime, endTime: $endTime, location: $location)';
}


}

/// @nodoc
abstract mixin class _$ApplicationJobPostingCopyWith<$Res> implements $ApplicationJobPostingCopyWith<$Res> {
  factory _$ApplicationJobPostingCopyWith(_ApplicationJobPosting value, $Res Function(_ApplicationJobPosting) _then) = __$ApplicationJobPostingCopyWithImpl;
@override @useResult
$Res call({
 int id, String jobTitle, DateTime workDate, String startTime, String endTime, JobLocation location
});


@override $JobLocationCopyWith<$Res> get location;

}
/// @nodoc
class __$ApplicationJobPostingCopyWithImpl<$Res>
    implements _$ApplicationJobPostingCopyWith<$Res> {
  __$ApplicationJobPostingCopyWithImpl(this._self, this._then);

  final _ApplicationJobPosting _self;
  final $Res Function(_ApplicationJobPosting) _then;

/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? jobTitle = null,Object? workDate = null,Object? startTime = null,Object? endTime = null,Object? location = null,}) {
  return _then(_ApplicationJobPosting(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,jobTitle: null == jobTitle ? _self.jobTitle : jobTitle // ignore: cast_nullable_to_non_nullable
as String,workDate: null == workDate ? _self.workDate : workDate // ignore: cast_nullable_to_non_nullable
as DateTime,startTime: null == startTime ? _self.startTime : startTime // ignore: cast_nullable_to_non_nullable
as String,endTime: null == endTime ? _self.endTime : endTime // ignore: cast_nullable_to_non_nullable
as String,location: null == location ? _self.location : location // ignore: cast_nullable_to_non_nullable
as JobLocation,
  ));
}

/// Create a copy of ApplicationJobPosting
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$JobLocationCopyWith<$Res> get location {
  
  return $JobLocationCopyWith<$Res>(_self.location, (value) {
    return _then(_self.copyWith(location: value));
  });
}
}


/// @nodoc
mixin _$WorkerApplicationCan {

 bool get withdraw;
/// Create a copy of WorkerApplicationCan
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$WorkerApplicationCanCopyWith<WorkerApplicationCan> get copyWith => _$WorkerApplicationCanCopyWithImpl<WorkerApplicationCan>(this as WorkerApplicationCan, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as WorkerApplicationCan;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is WorkerApplicationCan&&(identical(other.withdraw, _this.withdraw) || other.withdraw == _this.withdraw));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as WorkerApplicationCan;
  return Object.hash(runtimeType,_this.withdraw);
}

@override
String toString() {
  final _this = this as WorkerApplicationCan;
  return 'WorkerApplicationCan(withdraw: ${_this.withdraw})';
}


}

/// @nodoc
abstract mixin class $WorkerApplicationCanCopyWith<$Res>  {
  factory $WorkerApplicationCanCopyWith(WorkerApplicationCan value, $Res Function(WorkerApplicationCan) _then) = _$WorkerApplicationCanCopyWithImpl;
@useResult
$Res call({
 bool withdraw
});




}
/// @nodoc
class _$WorkerApplicationCanCopyWithImpl<$Res>
    implements $WorkerApplicationCanCopyWith<$Res> {
  _$WorkerApplicationCanCopyWithImpl(this._self, this._then);

  final WorkerApplicationCan _self;
  final $Res Function(WorkerApplicationCan) _then;

/// Create a copy of WorkerApplicationCan
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? withdraw = null,}) {
  return _then(WorkerApplicationCan(
withdraw: null == withdraw ? _self.withdraw : withdraw // ignore: cast_nullable_to_non_nullable
as bool,
  ));
}

}


/// Adds pattern-matching-related methods to [WorkerApplicationCan].
extension WorkerApplicationCanPatterns on WorkerApplicationCan {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _WorkerApplicationCan value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _WorkerApplicationCan() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _WorkerApplicationCan value)  $default,){
final _that = this;
switch (_that) {
case _WorkerApplicationCan():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _WorkerApplicationCan value)?  $default,){
final _that = this;
switch (_that) {
case _WorkerApplicationCan() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( bool withdraw)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _WorkerApplicationCan() when $default != null:
return $default(_that.withdraw);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( bool withdraw)  $default,) {final _that = this;
switch (_that) {
case _WorkerApplicationCan():
return $default(_that.withdraw);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( bool withdraw)?  $default,) {final _that = this;
switch (_that) {
case _WorkerApplicationCan() when $default != null:
return $default(_that.withdraw);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _WorkerApplicationCan implements WorkerApplicationCan {
  const _WorkerApplicationCan({required this.withdraw});
  factory _WorkerApplicationCan.fromJson(Map<String, dynamic> json) => _$WorkerApplicationCanFromJson(json);

@override final  bool withdraw;

/// Create a copy of WorkerApplicationCan
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$WorkerApplicationCanCopyWith<_WorkerApplicationCan> get copyWith => __$WorkerApplicationCanCopyWithImpl<_WorkerApplicationCan>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _WorkerApplicationCan&&(identical(other.withdraw, withdraw) || other.withdraw == withdraw));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,withdraw);
}

@override
String toString() {
    return 'WorkerApplicationCan(withdraw: $withdraw)';
}


}

/// @nodoc
abstract mixin class _$WorkerApplicationCanCopyWith<$Res> implements $WorkerApplicationCanCopyWith<$Res> {
  factory _$WorkerApplicationCanCopyWith(_WorkerApplicationCan value, $Res Function(_WorkerApplicationCan) _then) = __$WorkerApplicationCanCopyWithImpl;
@override @useResult
$Res call({
 bool withdraw
});




}
/// @nodoc
class __$WorkerApplicationCanCopyWithImpl<$Res>
    implements _$WorkerApplicationCanCopyWith<$Res> {
  __$WorkerApplicationCanCopyWithImpl(this._self, this._then);

  final _WorkerApplicationCan _self;
  final $Res Function(_WorkerApplicationCan) _then;

/// Create a copy of WorkerApplicationCan
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? withdraw = null,}) {
  return _then(_WorkerApplicationCan(
withdraw: null == withdraw ? _self.withdraw : withdraw // ignore: cast_nullable_to_non_nullable
as bool,
  ));
}


}

// dart format on
