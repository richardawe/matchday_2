@extends('errors.layout')
@section('title', 'Something went wrong')
@section('code', '500')
@section('heading')
We dropped the ball.
@endsection
@section('message')
Something broke on our side. Try again in a moment.
@endsection
@section('mood', 'defeated')
