@extends('development.composition-archive.layout')
@section('content')
<section class="unavailable"><h2>{{ ($notFound ?? false) ? '該当する保存レース・ページがありません' : '保存結果を確認できません' }}</h2><a href="/development/keirin/composition-archive/2025">一覧へ戻る</a></section>
@endsection
