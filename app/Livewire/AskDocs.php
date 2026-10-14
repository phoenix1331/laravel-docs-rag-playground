<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Ask the Laravel docs')]
class AskDocs extends Component
{
    public function render(): View
    {
        return view('livewire.ask-docs');
    }
}
