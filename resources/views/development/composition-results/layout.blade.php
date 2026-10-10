<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '構成モデル 予測・結果確認')</title>
    <link rel="stylesheet" href="/css/development-composition-results.css">
</head>
<body>
<header class="page-header">
    <div class="container">
        <p class="environment">開発用・2025年の保存データ</p>
        <h1>構成モデル 予測・結果確認</h1>
        <div class="restrictions">
            <p>学習期間内の照合です。将来レースの精度を示すものではありません</p>
            <p>発走前取得時点の保証なし／LIVE利用未承認</p>
        </div>
    </div>
</header>
<main class="container">
    @yield('content')
</main>
</body>
</html>
