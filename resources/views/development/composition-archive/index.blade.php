@extends('development.composition-archive.layout')
@section('content')
<nav class="breadcrumb">
    @if(config('composition_result_view.enabled') === true)
        <a href="/development/keirin/composition-results">固定10件の照合</a>
    @endif
    <span>2025年アーカイブ</span>
</nav>
<h2 class="archive-total">保存対象全{{ number_format($overview['matched']) }}レースの集計</h2>
<section class="metrics" aria-label="保存対象全体の集計">
    @foreach($overview['metrics'] as $metric)
    <div class="metric"><h2>{{ $metric['label'] }}</h2><p class="metric-rate">{{ $metric['rate'] }}</p><p>{{ $metric['numerator'] }} / {{ $metric['denominator'] }}</p>
        @if($metric['reason'])<p class="muted">評価対象なし</p>@endif
        @if($metric['excluded'])<p class="muted">評価除外 {{ $metric['excluded'] }} レース</p>@endif
    </div>
    @endforeach
</section>
<form method="get" action="/development/keirin/composition-archive/2025" class="archive-search">
    <label for="race-id">レースID</label><input id="race-id" name="race_id" type="text" inputmode="numeric" pattern="[1-9][0-9]*" required autocomplete="off"><button type="submit">検索</button>
</form>
<div class="section-heading"><h2>保存レース一覧</h2><span>{{ $overview['page'] }} / {{ $overview['page_count'] }} ページ・{{ count($overview['races']) }}件</span></div>
@include('development.composition-archive.pagination')
<div class="table-scroll" tabindex="0" aria-label="保存レース一覧"><table class="race-table">
<thead><tr><th>レースID</th><th>保存Primary<br>1 / 2 / 3着</th><th>実結果<br>1 / 2 / 3着</th><th>1着</th><th>2着</th><th>3着</th><th>参照</th></tr></thead><tbody>
@foreach($overview['races'] as $race)
<tr data-race-id="{{ $race['race_id'] }}"><th scope="row">{{ $race['race_id'] }}</th><td class="numeric">{{ implode(' / ', array_column($race['positions'], 'primary')) }}</td><td>{{ implode(' / ', array_column($race['positions'], 'actual')) }}</td>
@foreach($race['positions'] as $position)<td><span class="verdict {{ $position['state'] }}">{{ $position['decision'] }}</span></td>@endforeach
<td><a class="detail-link" href="/development/keirin/composition-archive/2025/{{ $race['race_id'] }}">詳細</a></td></tr>
@endforeach
</tbody></table></div>
@include('development.composition-archive.pagination')
<p class="record-metadata">保存日時 {{ $overview['saved_at'] }} · IN_SAMPLE_SAVED_PREDICTION_ARCHIVE · Gate/CI/bootstrap未実施</p>
@endsection
