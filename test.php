<?php

namespace Psr\Cache;

use Predis\Client;

// This is strictly so that we can work on these packages without publishing
// them yet. This whole file will eventually be removed.
//require_once '../psr-cache/vendor/autoload.php';
require_once 'vendor/autoload.php';

date_default_timezone_set('America/Chicago');

$pool = new PredisPool(new Client(array(
    'host' => '192.168.1.100'
)));

$pool->clear();

// Basic set/get operations.
$item = $pool->getItem('foo');
$item->set('foo value', '300');
$pool->save($item);
$item = $pool->getItem('bar');
$item->set('bar value', new \DateTime('now + 5min'));
$pool->save($item);

foreach ($pool->getItems(['foo', 'bar']) as $key => $item) {
    if ($key == 'foo') {
        assert($item->get() == 'foo value');
    }
    if ($key == 'bar') {
        assert($item->get() == 'bar value');
    }
}

// Update an existing item.
$items = $pool->getItems(['foo', 'bar']);
$items['bar']->set('new bar value');
array_map([$pool, 'save'], $items);

foreach ($pool->getItems(['foo', 'bar']) as $item) {
    if ($item->getKey() == 'foo') {
        assert($item->get() == 'foo value');
    }
    if ($item->getKey() == 'bar') {
        assert($item->get() == 'new bar value');
    }
}

// Defer saving to a later operation.
$item = $pool->getItem('baz')->set('baz value', '100');
$pool->saveDeferred($item);
$item = $pool->getItem('foo')->set('new foo value', new \DateTime('now + 1min'));
$pool->saveDeferred($item);
$item = $pool->getItem('bat')->set('bat value', new \DateTime('now - 1min'));
$pool->saveDeferred($item);
$pool->commit();

$items = $pool->getItems(['foo', 'bar', 'baz', 'bat']);
assert($items['foo']->get() == 'new foo value');
assert($items['bar']->get() == 'new bar value');
assert($items['baz']->get() == 'baz value');
assert($items['bat']->isHit() === false);
assert($items['bat']->get() === null);


// Test stampede protection
$pool->setStampedeProtection(true);
$item = $pool->getItem('bat');
assert($item->isHit() === false);
assert($item->get() === null);

$newpool = new PredisPool(new Client(array(
    'host' => '192.168.1.100'
)));
$newpool->setStampedeProtection(true);
$newitem = $newpool->getItem('bat');
assert($newitem->isHit() === true);
assert($newitem->get() === 'bat value');

$item->set('new bat value', 100);
$pool->save($item);

$newitem = $newpool->getItem('bat');
assert($newitem->isHit() === true);
assert($newitem->get() === 'new bat value');
