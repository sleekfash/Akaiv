<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the login page with the production-built theme', function () {
    $this->get('/admin/login')->assertOk()->assertSee('AKAIV');
});

it('renders case document and proceedings lists for an authorized member', function () {
    archiveContext($this);
    foreach (['cases', 'documents', 'proceedings'] as $resource) {
        $this->get('/admin/'.$resource)->assertOk();
    }
});
