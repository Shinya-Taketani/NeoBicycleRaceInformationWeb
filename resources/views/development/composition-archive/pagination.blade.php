<nav class="archive-pagination" aria-label="ページ移動">
    @if($overview['page'] > 1)<a rel="prev" href="?page={{ $overview['page'] - 1 }}">前の100件</a>@else<span>先頭ページ</span>@endif
    <span>{{ $overview['page'] }} / {{ $overview['page_count'] }}</span>
    @if($overview['page'] < $overview['page_count'])<a rel="next" href="?page={{ $overview['page'] + 1 }}">次の100件</a>@else<span>最終ページ</span>@endif
</nav>
