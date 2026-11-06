@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <h1 class="pagina-titulo">Hola, {{ auth()->user()->nombre }}</h1>
    <p class="pagina-subtitulo">Te damos la bienvenida a Transfer App.</p>
@endsection
