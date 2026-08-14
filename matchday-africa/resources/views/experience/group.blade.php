@extends('layouts.public')
@section('content')
<section class="md-phase"><div class="md-wrap md-narrow"><p class="md-eyebrow">LEAGUE CODE {{ $group->code }}</p><h1>{{ $group->name }}</h1><div class="md-table">@foreach($members as $member)<div><b>{{ $loop->iteration }}</b><span>{{ $member->name }}</span><strong>{{ $member->points }} pts</strong></div>@endforeach</div><button class="md-secondary md-share" data-share-title="Join {{ $group->name }}" data-share-text="Use code {{ $group->code }} to join my Matchday Africa prediction league.">Share invitation</button></div></section>
@endsection
