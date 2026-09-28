<?php

// routes/web.php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/dashboard');
});

Route::get('/dashboard', function () {
    return view('dashboard.index', [
        'totalAssociations' => 0,
        'totalMembers' => 0,
        'totalProjects' => 0,
        'totalTrainings' => 0,
    ]);
});
?>