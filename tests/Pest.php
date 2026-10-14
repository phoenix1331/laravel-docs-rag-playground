<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tests that need pgvector or tsvector; run them with "make test-pgsql".
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('pgsql')
    ->beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL with pgvector (make test-pgsql).');
        }
    })
    ->in('Pgsql');
