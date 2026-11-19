@extends('layouts.admin')
@section('title','Breaking news')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">BREAKING NEWS</p><h1>One headline at the top of the site.</h1><p>Shown on the homepage, match pages and Matchday Radio pages. Only the newest live item shows; items end on their own after their hours (at most {{ $maxHours }}). The agent's items go live straight away and are listed here with who posted them.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-breaking-admin">
    @if(session('success'))<div class="md-flash">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="md-flash md-flash-error">{{ $errors->first() }}</div>@endif

    <h2>On the site now</h2>
    @if($current)
        @include('partials.breaking', ['item' => $current, 'variant' => 'banner', 'preview' => true])
    @else
        <p>Nothing. The spot is hidden until someone posts an item.</p>
    @endif

    <h2>Post an item</h2>
    <form method="POST" action="{{ route('admin.breaking.store') }}" class="md-admin-form">
        @csrf
        <label>Headline (90 characters) <input name="headline" maxlength="90" required value="{{ old('headline') }}"></label>
        <label>Body (optional, 280 characters) <textarea name="body" maxlength="280" rows="2">{{ old('body') }}</textarea></label>
        <label>Link (optional: an article, match page, video or the source) <input name="link_url" type="url" placeholder="{{ url('/') }}/…" value="{{ old('link_url') }}"></label>
        <label>Image (optional, https) <input name="image_url" type="url" placeholder="https://…" value="{{ old('image_url') }}"></label>
        <label>Image credit and licence (needed with an image) <input name="image_credit" maxlength="200" placeholder="Jane Doe / Wikimedia Commons / CC BY-SA 4.0" value="{{ old('image_credit') }}"></label>
        <label>Hours on the site <input name="expires_in_hours" type="number" min="1" max="{{ $maxHours }}" value="{{ old('expires_in_hours', $defaultHours) }}"></label>
        <button class="md-primary">Put it live</button>
    </form>

    <h2>Items</h2>
    <table class="md-table">
        <thead><tr><th>Posted</th><th>Headline</th><th>By</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($items as $item)
            @php($status = $item->status())
            <tr>
                <td>{{ $item->created_at->timezone(config('app.timezone'))->format('j M H:i') }}</td>
                <td><strong>{{ $item->headline }}</strong>@if($item->body)<br><small>{{ $item->body }}</small>@endif
                    @if($item->link_url)<br><small><a href="{{ $item->link_url }}" target="_blank" rel="noopener">{{ $item->linkLabel() }}</a></small>@endif
                    @if($item->image_url)<br><small>Image: {{ $item->image_credit }}</small>@endif</td>
                <td>{{ $item->author?->name ?? 'unknown' }}<br><small>{{ $item->source === 'agent' ? 'agent API' : 'admin page' }}@if($item->editor && $item->updated_by !== $item->created_by) · edited by {{ $item->editor->name }}@endif</small></td>
                <td>{{ $status === 'live' ? ($current && $current->id === $item->id ? 'On the site' : 'Live (a newer item shows)') : ucfirst($status) }}<br>
                    <small>@if($item->ended_at)ended {{ $item->ended_at->timezone(config('app.timezone'))->format('j M H:i') }} by {{ $item->ender?->name ?? 'unknown' }}@else{{ $status === 'live' ? 'until' : 'expired' }} {{ $item->expires_at->timezone(config('app.timezone'))->format('j M H:i') }}@endif</small></td>
                <td>
                    @if($status === 'live')
                    <form method="POST" action="{{ route('admin.breaking.end', $item) }}" onsubmit="return confirm('End this item now? It leaves the site at once.')">@csrf<button class="md-secondary">End now</button></form>
                    <details><summary>Edit</summary>
                        <form method="POST" action="{{ route('admin.breaking.update', $item) }}" class="md-admin-form">
                            @csrf @method('PUT')
                            <label>Headline <input name="headline" maxlength="90" required value="{{ $item->headline }}"></label>
                            <label>Body <textarea name="body" maxlength="280" rows="2">{{ $item->body }}</textarea></label>
                            <label>Link <input name="link_url" type="url" value="{{ $item->link_url }}"></label>
                            <label>Image <input name="image_url" type="url" value="{{ $item->image_url }}"></label>
                            <label>Image credit <input name="image_credit" maxlength="200" value="{{ $item->image_credit }}"></label>
                            <label>Hours from now (blank keeps the end time) <input name="expires_in_hours" type="number" min="1" max="{{ $maxHours }}"></label>
                            <button class="md-primary">Save</button>
                        </form>
                    </details>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5">No breaking news yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $items->links() }}
</div>
@endsection
