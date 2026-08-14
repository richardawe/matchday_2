@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap"><p class="md-eyebrow">PRIVATE & PUBLIC TABLES</p><h1>Prediction leagues</h1><p class="md-lead">Bring your people together. Every correct call moves you up the table.</p>
@if(session('success'))<div class="md-notice">{{ session('success') }}</div>@endif
<div class="md-two"><form class="md-panel" method="POST" action="{{ route('groups.store') }}">@csrf<h2>Create a league</h2><input name="name" placeholder="League name" required><label><input type="checkbox" name="is_public" value="1"> Public league</label><button class="md-primary">Create league</button></form>
<form class="md-panel" method="POST" action="{{ route('groups.join') }}">@csrf<h2>Join with a code</h2><input name="code" placeholder="ABC123" maxlength="10" required><button class="md-secondary">Join league</button></form></div>
<div class="md-card-grid">@forelse($groups as $group)<a class="md-panel" href="{{ route('groups.show',$group) }}"><small>{{ $group->is_public?'PUBLIC':'PRIVATE' }}</small><h2>{{ $group->name }}</h2><p>{{ $group->members_count }} contenders · Code {{ $group->code }}</p></a>@empty<div class="md-panel"><h2>No leagues yet</h2><p>Create one and invite friends with the six-character code.</p></div>@endforelse</div></div></section>
@endsection
