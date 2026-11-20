@extends('layouts.admin')
@section('title','Settings')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">SETTINGS</p><h1>Site switches.</h1><p>Changes here take effect within a minute and win over the server's <code>.env</code>.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif
 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @else
 <h2>Agent publishing</h2>
 <p>When on, the agent can publish and unpublish the articles it wrote through <code>/api/agent</code>. Articles written by editors stay off limits either way. When off, those calls answer 403 and its articles stay drafts until an editor publishes them.</p>
 <form method="POST" action="{{ route('admin.settings.update') }}">@csrf
  <input type="hidden" name="agent_can_publish" value="0">
  <label><input type="checkbox" name="agent_can_publish" value="1" @checked($agentCanPublish)> The agent can publish its own articles</label>
  <button type="submit">Save</button>
 </form>
 <h2>Recent publishing</h2>
 <table class="md-signup-table"><thead><tr><th>When</th><th>Article</th><th>Action</th><th>By</th></tr></thead><tbody>
  @forelse($log as $row)
   <tr><td>{{ $row->created_at?->timezone(config('app.timezone'))->format('j M H:i') }}</td>
    <td>#{{ $row->blog_id }} {{ $row->blog?->title }}</td><td>{{ $row->action }}</td>
    <td>{{ $row->actor === 'agent' ? 'Agent' : 'Editor' }}{{ $row->user ? ' ('.$row->user->name.')' : '' }}</td></tr>
  @empty
   <tr><td colspan="4">Nothing yet.</td></tr>
  @endforelse
 </tbody></table>
 @endif
</div>
@endsection
