// GENERATED CODE - DO NOT MODIFY BY HAND
// coverage:ignore-file
// ignore_for_file: type=lint, type=warning, deprecated_member_use, deprecated_member_use_from_same_package
// ignore_for_file: unused_element, deprecated_member_use, deprecated_member_use_from_same_package, use_function_type_syntax_for_parameters, unnecessary_const, avoid_init_to_null, invalid_override_different_default_values_named, prefer_expression_function_bodies, annotate_overrides, invalid_annotation_target, unnecessary_question_mark

part of 'worker_me.dart';

// **************************************************************************
// FreezedGenerator
// **************************************************************************

// GENERATED CODE - DO NOT MODIFY BY HAND
// dart format off
T _$identity<T>(T value) => value;

/// @nodoc
mixin _$WorkerCompany {

 int get id; String get name; String get code;
/// Create a copy of WorkerCompany
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$WorkerCompanyCopyWith<WorkerCompany> get copyWith => _$WorkerCompanyCopyWithImpl<WorkerCompany>(this as WorkerCompany, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as WorkerCompany;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is WorkerCompany&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.name, _this.name) || other.name == _this.name)&&(identical(other.code, _this.code) || other.code == _this.code));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as WorkerCompany;
  return Object.hash(runtimeType,_this.id,_this.name,_this.code);
}

@override
String toString() {
  final _this = this as WorkerCompany;
  return 'WorkerCompany(id: ${_this.id}, name: ${_this.name}, code: ${_this.code})';
}


}

/// @nodoc
abstract mixin class $WorkerCompanyCopyWith<$Res>  {
  factory $WorkerCompanyCopyWith(WorkerCompany value, $Res Function(WorkerCompany) _then) = _$WorkerCompanyCopyWithImpl;
@useResult
$Res call({
 int id, String name, String code
});




}
/// @nodoc
class _$WorkerCompanyCopyWithImpl<$Res>
    implements $WorkerCompanyCopyWith<$Res> {
  _$WorkerCompanyCopyWithImpl(this._self, this._then);

  final WorkerCompany _self;
  final $Res Function(WorkerCompany) _then;

/// Create a copy of WorkerCompany
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? name = null,Object? code = null,}) {
  return _then(WorkerCompany(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,code: null == code ? _self.code : code // ignore: cast_nullable_to_non_nullable
as String,
  ));
}

}


/// Adds pattern-matching-related methods to [WorkerCompany].
extension WorkerCompanyPatterns on WorkerCompany {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _WorkerCompany value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _WorkerCompany() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _WorkerCompany value)  $default,){
final _that = this;
switch (_that) {
case _WorkerCompany():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _WorkerCompany value)?  $default,){
final _that = this;
switch (_that) {
case _WorkerCompany() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  String name,  String code)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _WorkerCompany() when $default != null:
return $default(_that.id,_that.name,_that.code);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  String name,  String code)  $default,) {final _that = this;
switch (_that) {
case _WorkerCompany():
return $default(_that.id,_that.name,_that.code);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  String name,  String code)?  $default,) {final _that = this;
switch (_that) {
case _WorkerCompany() when $default != null:
return $default(_that.id,_that.name,_that.code);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _WorkerCompany implements WorkerCompany {
  const _WorkerCompany({required this.id, required this.name, required this.code});
  factory _WorkerCompany.fromJson(Map<String, dynamic> json) => _$WorkerCompanyFromJson(json);

@override final  int id;
@override final  String name;
@override final  String code;

/// Create a copy of WorkerCompany
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$WorkerCompanyCopyWith<_WorkerCompany> get copyWith => __$WorkerCompanyCopyWithImpl<_WorkerCompany>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _WorkerCompany&&(identical(other.id, id) || other.id == id)&&(identical(other.name, name) || other.name == name)&&(identical(other.code, code) || other.code == code));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,name,code);
}

@override
String toString() {
    return 'WorkerCompany(id: $id, name: $name, code: $code)';
}


}

/// @nodoc
abstract mixin class _$WorkerCompanyCopyWith<$Res> implements $WorkerCompanyCopyWith<$Res> {
  factory _$WorkerCompanyCopyWith(_WorkerCompany value, $Res Function(_WorkerCompany) _then) = __$WorkerCompanyCopyWithImpl;
@override @useResult
$Res call({
 int id, String name, String code
});




}
/// @nodoc
class __$WorkerCompanyCopyWithImpl<$Res>
    implements _$WorkerCompanyCopyWith<$Res> {
  __$WorkerCompanyCopyWithImpl(this._self, this._then);

  final _WorkerCompany _self;
  final $Res Function(_WorkerCompany) _then;

/// Create a copy of WorkerCompany
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? name = null,Object? code = null,}) {
  return _then(_WorkerCompany(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,code: null == code ? _self.code : code // ignore: cast_nullable_to_non_nullable
as String,
  ));
}


}


/// @nodoc
mixin _$WorkerMe {

 int get id; String get name; String get email; WorkerCompany get company;
/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
$WorkerMeCopyWith<WorkerMe> get copyWith => _$WorkerMeCopyWithImpl<WorkerMe>(this as WorkerMe, _$identity);



@override
bool operator ==(Object other) {
  final _this = this as WorkerMe;
  return identical(this, other) || (other.runtimeType == runtimeType&&other is WorkerMe&&(identical(other.id, _this.id) || other.id == _this.id)&&(identical(other.name, _this.name) || other.name == _this.name)&&(identical(other.email, _this.email) || other.email == _this.email)&&(identical(other.company, _this.company) || other.company == _this.company));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
  final _this = this as WorkerMe;
  return Object.hash(runtimeType,_this.id,_this.name,_this.email,_this.company);
}

@override
String toString() {
  final _this = this as WorkerMe;
  return 'WorkerMe(id: ${_this.id}, name: ${_this.name}, email: ${_this.email}, company: ${_this.company})';
}


}

/// @nodoc
abstract mixin class $WorkerMeCopyWith<$Res>  {
  factory $WorkerMeCopyWith(WorkerMe value, $Res Function(WorkerMe) _then) = _$WorkerMeCopyWithImpl;
@useResult
$Res call({
 int id, String name, String email, WorkerCompany company
});


$WorkerCompanyCopyWith<$Res> get company;

}
/// @nodoc
class _$WorkerMeCopyWithImpl<$Res>
    implements $WorkerMeCopyWith<$Res> {
  _$WorkerMeCopyWithImpl(this._self, this._then);

  final WorkerMe _self;
  final $Res Function(WorkerMe) _then;

/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@pragma('vm:prefer-inline') @override $Res call({Object? id = null,Object? name = null,Object? email = null,Object? company = null,}) {
  return _then(WorkerMe(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,email: null == email ? _self.email : email // ignore: cast_nullable_to_non_nullable
as String,company: null == company ? _self.company : company // ignore: cast_nullable_to_non_nullable
as WorkerCompany,
  ));
}
/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$WorkerCompanyCopyWith<$Res> get company {
  
  return $WorkerCompanyCopyWith<$Res>(_self.company, (value) {
    return _then(_self.copyWith(company: value));
  });
}
}


/// Adds pattern-matching-related methods to [WorkerMe].
extension WorkerMePatterns on WorkerMe {
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

@optionalTypeArgs TResult maybeMap<TResult extends Object?>(TResult Function( _WorkerMe value)?  $default,{required TResult orElse(),}){
final _that = this;
switch (_that) {
case _WorkerMe() when $default != null:
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

@optionalTypeArgs TResult map<TResult extends Object?>(TResult Function( _WorkerMe value)  $default,){
final _that = this;
switch (_that) {
case _WorkerMe():
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

@optionalTypeArgs TResult? mapOrNull<TResult extends Object?>(TResult? Function( _WorkerMe value)?  $default,){
final _that = this;
switch (_that) {
case _WorkerMe() when $default != null:
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

@optionalTypeArgs TResult maybeWhen<TResult extends Object?>(TResult Function( int id,  String name,  String email,  WorkerCompany company)?  $default,{required TResult orElse(),}) {final _that = this;
switch (_that) {
case _WorkerMe() when $default != null:
return $default(_that.id,_that.name,_that.email,_that.company);case _:
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

@optionalTypeArgs TResult when<TResult extends Object?>(TResult Function( int id,  String name,  String email,  WorkerCompany company)  $default,) {final _that = this;
switch (_that) {
case _WorkerMe():
return $default(_that.id,_that.name,_that.email,_that.company);case _:
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

@optionalTypeArgs TResult? whenOrNull<TResult extends Object?>(TResult? Function( int id,  String name,  String email,  WorkerCompany company)?  $default,) {final _that = this;
switch (_that) {
case _WorkerMe() when $default != null:
return $default(_that.id,_that.name,_that.email,_that.company);case _:
  return null;

}
}

}

/// @nodoc
@JsonSerializable(createToJson: false)

class _WorkerMe implements WorkerMe {
  const _WorkerMe({required this.id, required this.name, required this.email, required this.company});
  factory _WorkerMe.fromJson(Map<String, dynamic> json) => _$WorkerMeFromJson(json);

@override final  int id;
@override final  String name;
@override final  String email;
@override final  WorkerCompany company;

/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@override @JsonKey(includeFromJson: false, includeToJson: false)
@pragma('vm:prefer-inline')
_$WorkerMeCopyWith<_WorkerMe> get copyWith => __$WorkerMeCopyWithImpl<_WorkerMe>(this, _$identity);



@override
bool operator ==(Object other) {
    return identical(this, other) || (other.runtimeType == runtimeType&&other is _WorkerMe&&(identical(other.id, id) || other.id == id)&&(identical(other.name, name) || other.name == name)&&(identical(other.email, email) || other.email == email)&&(identical(other.company, company) || other.company == company));
}

@JsonKey(includeFromJson: false, includeToJson: false)
@override
int get hashCode {
    return Object.hash(runtimeType,id,name,email,company);
}

@override
String toString() {
    return 'WorkerMe(id: $id, name: $name, email: $email, company: $company)';
}


}

/// @nodoc
abstract mixin class _$WorkerMeCopyWith<$Res> implements $WorkerMeCopyWith<$Res> {
  factory _$WorkerMeCopyWith(_WorkerMe value, $Res Function(_WorkerMe) _then) = __$WorkerMeCopyWithImpl;
@override @useResult
$Res call({
 int id, String name, String email, WorkerCompany company
});


@override $WorkerCompanyCopyWith<$Res> get company;

}
/// @nodoc
class __$WorkerMeCopyWithImpl<$Res>
    implements _$WorkerMeCopyWith<$Res> {
  __$WorkerMeCopyWithImpl(this._self, this._then);

  final _WorkerMe _self;
  final $Res Function(_WorkerMe) _then;

/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@override @pragma('vm:prefer-inline') $Res call({Object? id = null,Object? name = null,Object? email = null,Object? company = null,}) {
  return _then(_WorkerMe(
id: null == id ? _self.id : id // ignore: cast_nullable_to_non_nullable
as int,name: null == name ? _self.name : name // ignore: cast_nullable_to_non_nullable
as String,email: null == email ? _self.email : email // ignore: cast_nullable_to_non_nullable
as String,company: null == company ? _self.company : company // ignore: cast_nullable_to_non_nullable
as WorkerCompany,
  ));
}

/// Create a copy of WorkerMe
/// with the given fields replaced by the non-null parameter values.
@override
@pragma('vm:prefer-inline')
$WorkerCompanyCopyWith<$Res> get company {
  
  return $WorkerCompanyCopyWith<$Res>(_self.company, (value) {
    return _then(_self.copyWith(company: value));
  });
}
}

// dart format on
