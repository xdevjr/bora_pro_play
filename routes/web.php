<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('salas/{room:code}', 'pages::rooms.show')->name('rooms.show');
Route::livewire('salas/{room:code}/placares/{scoreboard}', 'pages::scoreboards.show')->name('rooms.scoreboards.show');
