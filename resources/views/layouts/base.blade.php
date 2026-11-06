{{-- Lo común a todas las páginas. Cada página o layout llena las secciones con @section. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · Transfer App</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    {{-- Va después de Bootstrap para poder cambiar sus valores. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    @yield('cuerpo')

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
