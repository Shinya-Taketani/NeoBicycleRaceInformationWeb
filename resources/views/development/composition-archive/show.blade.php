@extends('development.composition-archive.layout')
@section('title', 'レース'.$race['race_id'].' | 2025年保存予測')
@section('content')
<nav class="breadcrumb"><a href="/development/keirin/composition-archive/2025?page={{ $overview['page'] }}">一覧へ戻る</a><span>レース {{ $race['race_id'] }}</span></nav>
<div class="section-heading"><h2>レース {{ $race['race_id'] }}</h2><span>2025年</span></div>
<div class="positions">
@foreach($race['positions'] as $index => $position)
<div class="position"><h3>{{ $index + 1 }}着</h3><dl><div><dt>保存Primary</dt><dd>車番 {{ $position['primary'] }}</dd></div><div><dt>実結果</dt><dd>{{ $position['actual'] }}</dd></div></dl><p class="verdict {{ $position['state'] }}">{{ $position['decision'] }}</p>
@if($position['reason'])<p class="muted">{{ $position['reason'] }}</p><p class="muted">{{ $position['reason_code'] }}</p>@endif
</div>
@endforeach
</div>
<div class="section-heading"><h2>出走者別の保存確率</h2><span>{{ count($entries) }} 出走者</span></div>
<div class="table-scroll" tabindex="0" aria-label="出走者別確率"><table class="entry-table">
<thead><tr><th>車番</th><th>出走ID</th><th>1着周辺確率</th><th>2着周辺確率</th><th>3着周辺確率</th><th>実着順</th><th>結果status</th></tr></thead><tbody>
@foreach($entries as $entry)
<tr data-entry-id="{{ $entry['id'] }}" data-bike="{{ $entry['bike'] }}"><th scope="row">{{ $entry['bike'] }}</th><td class="numeric">{{ $entry['id'] }}</td><td class="numeric">{{ $entry['p1'] }}</td><td class="numeric">{{ $entry['p2'] }}</td><td class="numeric">{{ $entry['p3'] }}</td><td>{{ $entry['rank'] }}</td><td>{{ $entry['status'] }}</td></tr>
@endforeach
</tbody></table></div>
<dl class="missing-metadata"><div><dt>開催名・レース日・選手名</dt><dd>未収録</dd></div></dl>
<p class="record-metadata">IN_SAMPLE_SAVED_PREDICTION_ARCHIVE · Gate/CI/bootstrap未実施</p>
@endsection
