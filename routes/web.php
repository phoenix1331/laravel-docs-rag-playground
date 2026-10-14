<?php

declare(strict_types=1);

use App\Livewire\AskDocs;
use Illuminate\Support\Facades\Route;

Route::livewire('/', AskDocs::class)->name('ask');
