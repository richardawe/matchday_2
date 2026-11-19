@extends('layouts.admin')
@section('title','Agent access')
@section('header')
<div class="md-admin-heading"><div><p class="md-eyebrow">AGENT ACCESS</p><h1>The social media assistant.</h1><p>The agent role logs in to the <a href="{{ route('admin.studio.index') }}">Content studio</a>, the social queue and draft articles, and uses <code>/api/agent</code> with a token. It cannot open billing, payments, users, secrets or delete anything. Every API call and every change it makes in the admin is logged below. Guide: <code>docs/agent-api.md</code>.</p></div></div>
@endsection
@section('content')
<div class="md-xdesk md-signups">
 @if(session('success'))<p class="md-notice">{{ session('success') }}</p>@endif
 @if(session('error'))<p class="md-notice md-flash-error">{{ session('error') }}</p>@endif
 @if($errors->any())<p class="md-notice md-flash-error">{{ $errors->first() }}</p>@endif
 @if($plain)
  <section class="md-xguide"><span>NEW TOKEN (SHOWN ONCE)</span>
   <p>Copy it into the assistant's secure setting now. It is stored only as a hash and cannot be shown again.</p>
   <input readonly value="{{ $plain }}" onclick="this.select()" style="width:100%;font-family:monospace" aria-label="API token">
  </section>
 @endif
 @if(!$ready)
  <section class="md-xguide"><span>NOT SET UP</span><p>Run the migrations (<code>php artisan migrate --force</code>).</p></section>
 @else
 <h2>Agent login</h2>
 <table class="md-signup-table"><thead><tr><th>Name</th><th>Email</th><th>Token</th></tr></thead><tbody>
  @forelse($agents as $agent)
   <tr><td>{{ $agent->name }}</td><td>{{ $agent->email }}</td><td>
    <form method="POST" action="{{ route('admin.agent.token', $agent) }}">@csrf
     <label><input type="checkbox" name="revoke_others" value="1" checked> revoke older tokens</label>
     <button type="submit">Issue new token</button></form></td></tr>
  @empty
   <tr><td colspan="3">No agent yet.</td></tr>
  @endforelse
 </tbody></table>
 <form method="POST" action="{{ route('admin.agent.store') }}" style="max-width:32rem">@csrf
  <h3>Create or update the agent login</h3>
  <label style="display:block">Name <input name="name" value="{{ old('name', 'Matchday agent') }}" required style="width:100%"></label>
  <label style="display:block">Email <input type="email" name="email" value="{{ old('email', $agents->first()?->email) }}" required style="width:100%"></label>
  <label style="display:block">Password <small>(12+ characters; blank keeps the current one)</small> <input type="password" name="password" autocomplete="new-password" style="width:100%"></label>
  <button type="submit">Save agent login</button>
 </form>

 <h2>Tokens</h2>
 <table class="md-signup-table"><thead><tr><th>Token</th><th>Agent</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>
  @forelse($tokens as $token)
   <tr><td><code>{{ $token->prefix }}…</code></td><td>{{ $token->user?->email }}</td><td>{{ $token->created_at->setTimezone('Europe/London')->format('j M Y H:i') }}</td>
    <td>{{ $token->last_used_at?->setTimezone('Europe/London')->format('j M H:i') ?? 'never' }}</td>
    <td>@if($token->revoked_at)revoked {{ $token->revoked_at->format('j M') }}@else
     <form method="POST" action="{{ route('admin.agent.revoke', $token) }}">@csrf<button type="submit">Revoke</button></form>@endif</td></tr>
  @empty
   <tr><td colspan="5">No tokens.</td></tr>
  @endforelse
 </tbody></table>

 <h2>Audit log</h2>
 <table class="md-signup-table"><thead><tr><th>When</th><th>Where</th><th>Request</th><th>Status</th><th>Details</th></tr></thead><tbody>
  @forelse($log as $row)
   <tr><td>{{ $row->created_at?->setTimezone('Europe/London')->format('j M H:i:s') }}</td><td>{{ $row->channel }}</td>
    <td><code>{{ $row->method }} {{ $row->path }}</code></td><td>{{ $row->status }}</td>
    <td><small>{{ json_encode($row->params['values'] ?? ($row->params['fields'] ?? [])) }}</small></td></tr>
  @empty
   <tr><td colspan="5">Nothing yet.</td></tr>
  @endforelse
 </tbody></table>
 @endif
</div>
@endsection
