# Queues

<a name="running-the-queue-worker"></a>
## Running the Queue Worker

The worker process reads jobs from the queue and runs them one at a time. Each job is given a timeout, and a job that runs for too long is killed so the worker can carry on. This is paragraph one.

The worker process reads jobs from the queue and runs them one at a time. Each job is given a timeout, and a job that runs for too long is killed so the worker can carry on. This is paragraph two.

```php
$batch->add(new ImportCsv(1, 100));
$batch->add(new ImportCsv(2, 100));
$batch->add(new ImportCsv(3, 100));
$batch->add(new ImportCsv(4, 100));
$batch->add(new ImportCsv(5, 100));
$batch->add(new ImportCsv(6, 100));
$batch->add(new ImportCsv(7, 100));
$batch->add(new ImportCsv(8, 100));
$batch->add(new ImportCsv(9, 100));
$batch->add(new ImportCsv(10, 100));
$batch->add(new ImportCsv(11, 100));

// A blank line inside the block must not split it
```

The worker process reads jobs from the queue and runs them one at a time. Each job is given a timeout, and a job that runs for too long is killed so the worker can carry on. This is paragraph three.

The worker process reads jobs from the queue and runs them one at a time. Each job is given a timeout, and a job that runs for too long is killed so the worker can carry on. This is paragraph four.
