<?php

define('CLI_SCRIPT', 1);
require_once('../../config.php');

// Assume composer install has been run
require_once($CFG->dirroot . '/local/doctrine/vendor/autoload.php');

$generator = new \local_doctrine\Tools\Generator();
$generator->run('mappings');

purge_all_caches();