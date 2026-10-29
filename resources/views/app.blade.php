<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101210">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NoLife</title>
    <link rel="icon" type="image/png" href="/brand/nolife-mark.png">
    @vite('resources/js/app.ts')
</head>
<body>
    <div id="app"></div>
    <noscript>NoLife needs JavaScript to display your collection.</noscript>
</body>
</html>
