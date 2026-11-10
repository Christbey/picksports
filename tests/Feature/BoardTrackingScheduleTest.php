<?php

use App\Models\User;

it('defers the existing tag manager container on the signed-in CFB board only', function () {
    $this->withoutVite();

    $this->get('/')->assertSuccessful()
        ->assertSee('data-wait-for-board="false"', false)
        ->assertSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js')
        ->assertSee('www.googletagmanager.com/ns.html?id=GTM-P5NFGS3P');

    $this->actingAs(User::factory()->create());
    $this->get('/cfb/predictions')->assertSuccessful()
        ->assertSee('data-wait-for-board="true"', false)
        ->assertDontSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js')
        ->assertSee('www.googletagmanager.com/ns.html?id=GTM-P5NFGS3P');
    $this->get('/dashboard')->assertSuccessful()
        ->assertSee('data-wait-for-board="false"', false);
});
