<?php

declare(strict_types=1);

use App\Retrieval\Retriever;

it('rejects unknown retrieval modes', function () {
    app(Retriever::class)->retrieve('queues', '13.x', mode: 'keyword');
})->throws(InvalidArgumentException::class, 'Unknown retrieval mode [keyword]');
