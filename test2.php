<?php

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

define('CLI_SCRIPT', 1);
require_once('../../config.php');

// Assume composer install has been run
require_once($CFG->dirroot . '/local/doctrine/vendor/autoload.php');

$paths = array($CFG->dirroot . '/local/doctrine/classes/Entity');
$isDevMode = true;

$conn = array(
    'driver' => 'pdo_mysql',
    'user' => $CFG->dbuser,
    'password' => $CFG->dbpass,
    'dbname' => $CFG->dbname
);

$config = Setup::createAnnotationMetadataConfiguration($paths, $isDevMode, null, null, false);
$em = EntityManager::create($conn, $config);

$user = $em->find('local_doctrine\Entity\User', 2);

mtrace($user->getFirstname());

$enrolments = $user->getUserEnrolmentsAsUser();

foreach ($enrolments as $e) {
	$enrol = $e->getEnrol();
	$course = $enrol->getCourse();
	mtrace($course->getFullname());
	var_dump($course);
}
