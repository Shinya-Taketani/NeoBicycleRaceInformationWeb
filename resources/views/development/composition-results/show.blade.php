@extends('development.composition-results.layout')
@section('title', 'レース'.$race['race_id'].' | 構成モデル 予測・結果確認')

@section('content')
<nav class="breadcrumb"><a href="{{ route('development.composition-results.index', [], false) }}">一覧へ戻る</a><span>レース {{ $race['race_id'] }}</span></nav>
<section aria-labelledby="detail-heading">
    <div class="section-heading"><h2 id="detail-heading">レース {{ $race['race_id'] }}</h2><span>{{ $overview['year'] }}年</span></div>
    <p class="request-id">依頼ID <span>{{ $race['request_id'] }}</span></p>
    <div class="positions">
        @foreach($race['positions'] as $index => $position)
            <div class="position">
                <h3>{{ $index + 1 }}着</h3>
                <dl><div><dt>保存Primary</dt><dd>車番 {{ $position['primary'] }}</dd></div><div><dt>実結果</dt><dd>{{ $position['actual'] }}</dd></div></dl>
                <p><span class="verdict {{ $position['state'] }}">{{ $position['decision'] }}</span></p>
                @if($position['reason'])<p class="muted">{{ $position['reason'] }}</p>@endif
                @if($position['reason_code'])<p class="muted">{{ $position['reason_code'] }}</p>@endif
            </div>
        @endforeach
    </div>
</section>
<section class="entrants" aria-labelledby="entrants-heading">
    <div class="section-heading"><h2 id="entrants-heading">出走者別の保存確率</h2><span>{{ count($entries) }} 出走者</span></div>
    <div class="table-scroll" tabindex="0" aria-label="出走者別確率">
        <table class="entry-table">
            <thead><tr><th scope="col">車番</th><th scope="col">出走ID</th><th scope="col">1着周辺確率</th><th scope="col">2着周辺確率</th><th scope="col">3着周辺確率</th><th scope="col">実着順</th><th scope="col">結果status</th></tr></thead>
            <tbody>
            @foreach($entries as $entry)
                <tr data-entry-id="{{ $entry['id'] }}" data-bike="{{ $entry['bike'] }}">
                    <th scope="row">{{ $entry['bike'] }}</th><td class="numeric">{{ $entry['id'] }}</td>
                    <td class="numeric">{{ $entry['p1'] }}</td><td class="numeric">{{ $entry['p2'] }}</td><td class="numeric">{{ $entry['p3'] }}</td>
                    <td>{{ $entry['rank'] }}</td><td>{{ $entry['status'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
<dl class="missing-metadata"><div><dt>開催名・レース日・選手名</dt><dd>未収録</dd></div></dl>
@include('development.composition-results.metadata')
@endsection
