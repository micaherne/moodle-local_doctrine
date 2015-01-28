<?php

use Symfony\Component\Yaml\Yaml;

use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\EntityGenerator;

define('CLI_SCRIPT', 1);
require_once('../../../config.php');

// Assume composer install has been run
require_once($CFG->dirroot . '/local/doctrine/vendor/autoload.php');

$outdir = __DIR__ . '/../mappings';

$db = moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
$dbman = $db->get_manager();

$schema = $dbman->get_install_xml_schema();

$targetnamespace = 'local_doctrine';

$targetnamespaceparts = explode('\\', $targetnamespace);
$targetnamespacepartslc = array_map('lcfirst', $targetnamespaceparts);

$definitions = array();
$onetomanys = array();
$tabletoclassname = array();
$tabletofilename = array();
foreach ($schema->getTables() as $table) {
	$tablename = $table->getName();

	// Work out the filename and classname
	// Should be functions
	$nameparts = explode('_', $tablename);
	$namepartsuc = array_map('ucfirst', $nameparts);
	$tabletofilename[$tablename] = implode(".", array_merge($targetnamespaceparts, $namepartsuc)) . '.dcm.yml';
	$tabletoclassname[$tablename] = implode('\\', array_merge($targetnamespaceparts, $namepartsuc));

	$definitions[$table->getName()] = array("type" => "entity", "table" => "{$CFG->prefix}{$tablename}");

	$idkey = null;
	$ignorefields = array();
	foreach ($table->getKeys() as $key) {
		if ($key->getType() == XMLDB_KEY_PRIMARY) {
			$idkey = $key;
		}
		if ($key->getType() == XMLDB_KEY_FOREIGN || $key->getType() == XMLDB_KEY_FOREIGN_UNIQUE) {
			// Don't add foreign key fields to mapping
			foreach ($key->getFields() as $keyfield) {
				$ignorefields[] = $keyfield;
			}

			$reftable = $key->getRefTable();
			if (!isset($onetomanys[$reftable])) {
				$onetomanys[$reftable] = array();
			}
			$onetomanys[$reftable][$tablename] = $key->getRefFields();

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
		$definitions[$table->getName()]['indexes'] = $indexes;
	}

	$idfield = $table->getField($idkey->getFields()[0]);
	if (is_null($idfield)) {
		mtrace("$tablename has no field named " . $idkey->getName());
		continue;
	}

	$definitions[$table->getName()]['id'] = array($idfield->getName() => field_definition($idfield));

	$fields = array();
	foreach ($table->getFields() as $field) {
		if ($field->getName() == $idfield->getName()) {
			continue;
		}

		if (in_array($field->getName(), $ignorefields)) {
			continue;
		}

		$fields[$field->getName()] = field_definition($field);
	}

	$definitions[$table->getName()]['fields'] = $fields;

}

// Add one-to-may relations
foreach ($onetomanys as $table => $details) {
	$otm = array();
	foreach ($details as $reftable => $reffields) {
		$otm[$reftable] = array(
			'targetEntity' => $tabletoclassname[$reftable],
			// TODO: Support composite foreign keys?
			'mappedBy' => implode('', $reffields)
		);
	}
	$definitions[$table]['oneToMany'] = $otm;
}

foreach ($definitions as $tablename => $definition) {
	$classname = $tabletoclassname[$tablename];
	$filename = $tabletofilename[$tablename];
	// Write YAML mapping file
	$mapping = Yaml::dump(array($classname => $definition), 6, 2);
	$out = fopen("$outdir/$filename", "w");
	fputs($out, $mapping);
	fclose($out);
}

// Generate metadata from YAML
$driver = new YamlDriver(array($outdir));

$metadatas = array();
$f = new DisconnectedClassMetadataFactory();

foreach ($driver->getAllClassNames() as $c) {
	$metadata = new ClassMetadata($c);
	$driver->loadMetadataForClass($c, $metadata);
	$metadatas[] = $metadata;
}

$destpath = $outdir = __DIR__ . '/../temp/';
$extend = null; // UNUSED: class to extend


// Generate entity classes
if (count($metadatas)) {
	// Create EntityGenerator
	$entityGenerator = new EntityGenerator();

	$entityGenerator->setGenerateAnnotations(true);
	$entityGenerator->setGenerateStubMethods(true);
	$entityGenerator->setRegenerateEntityIfExists(true);
	$entityGenerator->setUpdateEntityIfExists(true);
	$entityGenerator->setNumSpaces(4);

	if ($extend !== null) {
		$entityGenerator->setClassToExtend($extend);
	}

	foreach ($metadatas as $metadata) {
		mtrace(
				sprintf('Processing entity "<info>%s</info>"', $metadata->name)
		);
	}

	// Generating Entities
	$entityGenerator->generate($metadatas, $destpath);

	// Outputting information message
	mtrace(PHP_EOL . sprintf('Entity classes generated to "<info>%s</INFO>"', $destpath));
} else {
	mtrace('No Metadata Classes to process.');
}

// Move to classes folder for autoloading
$classesdir = $CFG->dirroot.'/local/doctrine/classes';
rmdir($classesdir);
rename($destpath . '/local_doctrine', $classesdir);

function field_definition(xmldb_field $field) {
	$result = array();
	$options = array();

	if ($field->getSequence()) {
		$result['generator'] = array('strategy' => 'auto');
	}

	// Map XMLDB type names to Doctrine ones
	$xmldbtypename = $field->getXMLDBTypeName($field->getType());
	$typename = $xmldbtypename;
	switch ($xmldbtypename) {
		case 'int':
			$typename = 'integer';
			break;
		case 'number':
			$typename = 'decimal';
			break;
		case 'char':
			$typename = 'string';
			break;
	}
	$result['type'] = $typename; // TODO: Map type names

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