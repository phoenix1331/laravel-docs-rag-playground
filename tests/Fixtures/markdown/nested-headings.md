# Queues

- [Introduction](#introduction)
    - [Driver Notes and Prerequisites](#driver-prerequisites)
- [Job Batching](#job-batching)
    - [Dispatching Batches](#dispatching-batches)

<a name="introduction"></a>
## Introduction

Laravel queues provide a unified queueing API across a variety of different queue backends, such as Amazon SQS, Redis, or even a relational database.

<a name="driver-prerequisites"></a>
### Driver Notes and Prerequisites

Each queue driver has its own requirements. The database driver needs a table to hold the jobs, which the default migration already creates for you.

#### Redis

In order to use the redis queue driver, you should configure a Redis database connection in your config/database.php configuration file.

<a name="job-batching"></a>
## Job Batching

Laravel's job batching feature allows you to easily execute a group of jobs and then perform some action when the batch of jobs has completed executing.

<div class="content-list" markdown="1">

### Dispatching Batches

To dispatch a batch of jobs, you should use the batch method of the Bus facade. You may use the then, catch and finally callbacks to run code when the batch completes.

</div>
