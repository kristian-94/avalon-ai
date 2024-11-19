<?php

use Illuminate\Support\Facades\Route;

Route::any('/', static function () {
    return view('welcome');
});
