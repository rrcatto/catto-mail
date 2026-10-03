<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Configuration comes from the real environment (the test harness passes the
// contract variables); there is no .env file.
umask(0o002);
