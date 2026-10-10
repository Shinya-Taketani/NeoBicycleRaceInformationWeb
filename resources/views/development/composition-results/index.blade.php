@extends('development.composition-results.layout')

@section('content')
<section class="metrics" aria-label="固定対象の集計">
    @foreach($overview['metrics'] as $metric)
        <div class="metric">
            <h2>{{ $metric['label'] }}</h2>
            <p class="metric-rate">{{ $metric['rate'] }}</p>
            <p>{{ $metric['numerator'] }} / {{ $metric['denominator'] }}</p>
            @if($metric['reason'])<p class="muted">評価対象なし</p>@endif
            @if($metric['excluded'])<p class="muted">評価除外 {{ $metric['excluded'] }} レース</p>@endif
        </div>
    @endforeach
</section>
<section class="race-list" aria-labelledby="races-heading">
    <div class="section-heading"><h2 id="races-heading">保存レース一覧</h2><span>{{ $overview['matched'] }} レース</span></div>
    <div class="table-scroll" tabindex="0" aria-label="レース一覧">
        <table class="race-table">
            <thead><tr><th scope="col">レースID</th><th scope="col">保存Primary<br>1 / 2 / 3着</th><th scope="col">実結果<br>1 / 2 / 3着</th><th scope="col">1着</th><th scope="col">2着</th><th scope="col">3着</th><th scope="col">参照</th></tr></thead>
            <tbody>
            @foreach($overview['races'] as $race)
                <tr data-race-id="{{ $race['race_id'] }}">
                    <th scope="row">{{ $race['race_id'] }}</th>
                    <td class="numeric">{{ implode(' / ', array_column($race['positions'], 'primary')) }}</td>
                    <td>{{ implode(' / ', array_column($race['positions'], 'actual')) }}</td>
                    @foreach($race['positions'] as $position)
                        <td><span class="verdict {{ $position['state'] }}">{{ $position['decision'] }}</span></td>
                    @endforeach
                    <td><a class="detail-link" href="{{ route('development.composition-results.show', ['raceId' => $race['race_id']], false) }}" aria-label="レース{{ $race['race_id'] }}の詳細">詳細</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@include('development.composition-results.metadata')
@endsection
