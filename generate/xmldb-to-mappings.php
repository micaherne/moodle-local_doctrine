<?php

use Symfony\Component\Yaml\Yaml;
define('CLI_SCRIPT', 1);
require_once('../../../config.php');

// Assume composer install has been run
require_once($CFG->dirroot . '/local/doctrine/vendor/autoload.php');

$outdir = __DIR__ . '/../mappings';

$db = moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
$dbman = $db->get_manager();

$schema = $dbman->get_install_xml_schema();

$targetnamespace = 'Moodle\\Doctrine';

$targetnamespaceparts = explode('\\', $targetnamespace);
$targetnamespacepartslc = array_map('lcfirst', $targetnamespaceparts);

foreach ($schema->getTables() as $table) {
	$tablename = $table->getName();

	// Basic info
	$definition = array("type" => "entity", "table" => "{$CFG->prefix}{$tablename}");

	$idkey = null;
	foreach ($table->getKeys() as $key) {
		if ($key->getType() == XMLDB_KEY_PRIMARY) {
			$idkey = $key;
		}
		if ($key->getType() == XMLDB_KEY_FOREIGN || $key->getType() == XMLDB_KEY_FOREIGN_UNIQUE) {
			mtrace("$tablename has foreign key. Skipping for now");
			continue 2;
		}
	}

	if (is_null($idkey)) {
		mtrace("$tablename has no primary key");
		continue;
	}


	// Indexes
	$indexes = array();
	foreach ($table->getKeys() as $key) {
		if ($key->getType() == XMLDB_KEY_PRIMARY) {
			continue; // primary key is dealt with as id
		}
		if ($key->getType() == XMLDB_KEY_UNIQUE) {
			$indexes[$key->getName()] = array('columns' => $key->getFields());
		}
	}

	if (count($indexes) > 0) {
		$definition['indexes'] = $indexes;
	}

	$idfield = $table->getField($idkey->getFields()[0]);
	if (is_null($idfield)) {
		mtrace("$tablename has no field named " . $idkey->getName());
		continue;
	}

	$definition['id'] = array($idfield->getName() => field_definition($idfield));

	foreach ($table->getFields() as $field) {
		if ($field->getName() == $idfield->getName()) {
			continue;
		}

		$definition[$field->getName()] = field_definition($field);
	}

	// Write YAML mapping file
	$nameparts = explode('_', $tablename);
	$namepartsuc = array_map('ucfirst', $nameparts);
	$filename = implode(".", array_merge($targetnamespacepartslc, $nameparts)) . '.dcm.yml';
	$classname = implode('\\', array_merge($targetnamespaceparts, $namepartsuc));
	$mapping = Yaml::dump(array($classname => $definition), 6, 2);
	$out = fopen("$outdir/$filename", "w");
	fputs($out, $mapping);
	fclose($out);

	// Use Doctrine\ORM\Tools\EntityGenerator to generate entities
	// See Doctrine\ORM\Tools\Console\Command\GenerateEntitiesCommand
}

function field_definition(xmldb_field $field) {
	$result = array();
	$options = array();

	if ($field->getSequence()) {
		$result['generator'] = array('strategy' => 'auto');
	}

	$result['type'] = $field->getXMLDBTypeName($field->getType()); // TODO: Map type names

	// Field length is 0 for TEXT fields
	if (!is_null($field->getLength()) && $field->getLength() > 0) {
		$result['length'] = (int) $field->getLength(); // TODO: Any calculation needed here?
	}

	if ($field->getNotNull()) {
		$result['nullable'] = false;
	}

	// TODO: Decimals attribute?

	if (!is_null($field->getDefault())) {
		$options['default'] = $field->getDefault();
	}

	if (!is_null($field->getComment())) {
		$options['comment'] = $field->getComment();
	}

	if (count($options) > 0) {
		$result['options'] = $options;
	}

	return $result;
}