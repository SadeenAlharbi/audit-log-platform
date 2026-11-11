<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — API v1</title>
    <style>body { margin: 0; }</style>
</head>
<body>
    <redoc spec-url="{{ route('api.v1.openapi') }}" hide-download-button="false"></redoc>
    <script src="https://cdn.jsdelivr.net/npm/redoc@2.4.0/bundles/redoc.standalone.js"></script>
</body>
</html>
