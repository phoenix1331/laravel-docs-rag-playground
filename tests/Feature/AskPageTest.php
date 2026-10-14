<?php

declare(strict_types=1);
use Laravel\Horizon\Horizon;

it('serves the ask page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Ask the Laravel docs')
        ->assertSeeLivewire('ask-docs');
});

it('links to horizon when it may be viewed', function () {
    Horizon::auth(fn () => true);

    $this->get('/')->assertSee('href="'.url('horizon').'"', escape: false);
});

it('hides the horizon link when it may not be viewed', function () {
    Horizon::auth(fn () => false);

    $this->get('/')->assertDontSee('href="'.url('horizon').'"', escape: false);
});
