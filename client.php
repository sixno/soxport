<?php

use \Workerman\Worker;

require_once __DIR__.'/Workerman/Autoloader.php';
require_once __DIR__.'/config.php';

build_client_worker();

Worker::runAll();

?>