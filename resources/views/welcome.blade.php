<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'E-commerce') }}</title>
    <style>
        body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fb;color:#182033}
        main{max-width:620px;padding:3rem;background:white;border-radius:20px;box-shadow:0 12px 40px #15213a12}
        h1{margin-top:0}p{line-height:1.7;color:#526078}.ok{color:#087443;font-weight:700}
    </style>
</head>
<body>
<main>
    <p class="ok">Laravel is running</p>
    <h1>{{ config('app.name', 'E-commerce') }}</h1>
    <p>The PHP application has started successfully. Storefront and administration modules can now be added to this Laravel foundation.</p>
</main>
</body>
</html>
