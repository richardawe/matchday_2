@extends('layouts.public')
@section('content')
<article class="md-wrap md-radio-page"><h1>Terms of use</h1>
<div class="md-radio-transcript"><h2>Radio and news sources</h2>
<p>Matchday Africa Radio provides short, original football bulletins based on source reports and match data. News sources may include <a href="https://www.bbc.com/sport/football" rel="noopener">BBC Sport</a> and <a href="https://www.theguardian.com/football" rel="noopener">The Guardian Football</a>. Sources are credited in the bulletin and linked in each edition’s information. Matchday Africa is independent and is not endorsed by these publishers.</p>
<p>Publisher names, reports and other material remain the property of their respective owners. Source attribution does not grant a licence or transfer copyright. The bulletin does not reproduce full articles; follow source links for the original reporting.</p>
<h2>AI and accuracy</h2><p>{{ config('radio.disclosure') }} Transfer rumours are unconfirmed unless explicitly stated otherwise. Reports and match data can change after recording; check the original source for the latest information.</p>
<h2>Listening and sharing</h2><p>You may share links to Matchday Africa bulletins. Third-party material remains subject to its owner’s terms. The service is provided as available; editions may be delayed or withdrawn when checks fail.</p>
</div></article>
@endsection
