@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow"><p class="md-eyebrow">STAY IN THE STORY</p><h1>Alerts & weekly digest</h1><form class="md-panel md-settings" method="POST" action="{{ route('notification-settings.update') }}">@csrf @method('PUT')
@foreach(['match_alerts'=>'Match alerts','prediction_reminders'=>'Prediction deadline reminders','weekly_digest'=>'Weekly Matchday digest'] as $key=>$label)<label><span><strong>{{ $label }}</strong></span><input type="checkbox" name="{{ $key }}" value="1" {{ $preferences->$key?'checked':'' }}></label>@endforeach
<label><span><strong>Digest day</strong></span><select name="digest_day">@foreach(['monday','friday','sunday'] as $day)<option value="{{ $day }}" {{ $preferences->digest_day===$day?'selected':'' }}>{{ ucfirst($day) }}</option>@endforeach</select></label><button class="md-primary">Save preferences</button></form></div></section>
@endsection
