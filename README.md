# Scheduler

PHP script providing basic scheduling functionality.

## Installation

Install the latest version using `composer require riesenia/scheduler`

Or add to your *composer.json* file as a requirement:

```json
{
    "require": {
        "riesenia/scheduler": "~1.0"
    }
}
```

## Usage

Constructor takes two parameters:

* items - array of integers - item IDs
* terms - array of *TermInterface*

```php
use Riesenia\Scheduler\Scheduler;

$items = [1, 2];
$terms = [$term1, $term2, $term3, $term4];

$scheduler = new Scheduler($items, $terms);
```

### Adding terms and Items

Items and Terms can be also added separately. All added terms have to implement *TermInterface*.

```php
$scheduler->addItem(3);
$scheduler->addTerm($term5);
```

### TermInterface

Term is defined by its starting and ending date (*getFrom()* and *getTo()* methods). Moreover it can be locked to specific item by providing its ID in *getLockedId()* method.

### Scheduling

Calling *schedule()* method distibutes terms to items correctly. If this is not possible, scheduler throws *SchedulerException* with the information which terms overlap.

```php
use Riesenia\Scheduler\SchedulerException;

try {
    $scheduler->schedule();

    // get all the terms with reassigned item IDs
    $scheduler->getTerms();
} catch (SchedulerException $e) {
    \var_dump($e->getConflictingTerms());
}
```
