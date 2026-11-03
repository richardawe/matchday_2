@extends('layouts.admin')
@section('title','Messages to users')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">MESSAGES</p><h1>Tell your users something.</h1><p>Each message lands in every recipient's inbox (the bell). Tick push to send it to their phones too: {{ number_format($devices) }} {{ \Illuminate\Support\Str::plural('device', $devices) }} have alerts on{{ $pushReady ? '' : ' (push is off: PUSH_ENABLED and VAPID keys)' }}.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk">
    @if(session('success'))<div class="md-flash">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="md-flash md-flash-error">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('admin.messages.store') }}" class="md-admin-form" onsubmit="return confirm('Send this message now? It cannot be unsent.')">
        @csrf
        <label>Title <input name="title" maxlength="120" required value="{{ old('title') }}"></label>
        <label>Message <textarea name="body" maxlength="1000" rows="4" required>{{ old('body') }}</textarea></label>
        <label>Link (optional) <input name="url" type="url" placeholder="{{ url('/') }}/…" value="{{ old('url') }}"></label>
        <label>Who gets it
            <select name="audience">@foreach(\App\Models\Announcement::AUDIENCES as $key => $label)<option value="{{ $key }}">{{ $label }} ({{ number_format($counts[$key]) }})</option>@endforeach</select>
        </label>
        <label class="md-check"><input type="checkbox" name="push" value="1" {{ $pushReady ? '' : 'disabled' }}> Also send to phones</label>
        <button class="md-primary">Send</button>
    </form>

    <h2>Sent</h2>
    <table class="md-table">
        <thead><tr><th>When</th><th>Title</th><th>Audience</th><th>Inboxes</th><th>Push</th><th>By</th></tr></thead>
        <tbody>
        @forelse($announcements as $a)
            <tr><td>{{ $a->created_at->timezone(config('app.timezone'))->format('j M H:i') }}</td><td><strong>{{ $a->title }}</strong><br><small>{{ \Illuminate\Support\Str::limit($a->body, 90) }}</small></td>
                <td>{{ $a->audience }}</td><td>{{ number_format($a->recipients) }}</td><td>{{ $a->pushed ? 'yes' : 'no' }}</td><td>{{ $a->author?->name }}</td></tr>
        @empty
            <tr><td colspan="6">No messages sent yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
