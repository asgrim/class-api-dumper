<?php

declare(strict_types=1);

namespace Asgrim\ClassApiDumper;

require_once __DIR__ . '/../vendor/autoload.php';

use Asgrim\ClassApiDumper\Command\DumpClassApi;
use Symfony\Component\Console\Application;

$app = new Application('class-api-dumper');

/** @noinspection UnusedFunctionResultInspection */
$app->add(new DumpClassApi());

/** @noinspection PhpUnhandledExceptionInspection */
$app->run();
