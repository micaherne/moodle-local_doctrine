<?php

namespace local_doctrine\Tools;

use Symfony\Component\Yaml\Yaml;

use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\EntityGenerator;

class Generator {

    public $mappingsdir;
    const TARGET_NAMESPACE = 'local_doctrine\\Entity';

    function __construct($mappingsdir = 'mappings') {
        $this->mappingsdir = $mappingsdir;
    }

    function run() {
        global $CFG, $DB;

        $db = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $dbman = $db->get_manager();

        $schema = $dbman->get_install_xml_schema();

        $definitions = [];
        $joins = []; // tables with foreign key link to another
        foreach ($schema->getTables() as $table) {
            $tablename = $table->getName();

            $definitions[$table->getName()] = ["type" => "entity", "table" => "{$CFG->prefix}{$tablename}"];

            $idkey = null;
            $ignorefields = [];
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

                    /* We need to work out if any tables are linked to other
                     * tables by more than one column so we can make sure we create
                     * manyToOne and oneToMany correctly
                     */
                    if (!isset($joins[$tablename]) || !isset($joins[$tablename][$reftable])) {
                    	$joins[$tablename] = [$reftable => [$key]];
                    } else {
                        $joins[$tablename][$reftable][] = $key;
                    }

                    // Can't add manyToOnes here as we won't know the inversedBy name yet

                    // Add a many to one
                    /* if (!isset($definitions[$table->getName()]['manyToOne'])) {
                    	$definitions[$table->getName()]['manyToOne'] = [];
                    }
                    $definitions[$table->getName()]['manyToOne'][$reftable] = [
                    	'targetEntity' => $this->classnameForTable($reftable),
                    	'joinColumn' => [
                    		// TODO: Support composite keys
                    		'name' => implode('', $key->getFields()),
                    		'referencedColumnName' => implode('', $key->getRefFields())
                    	]
                    ]; */


                }
            }

            if (is_null($idkey)) {
                mtrace("$tablename has no primary key");
                continue;
            }


            // Indexes
            $indexes = [];
            foreach ($table->getKeys() as $key) {
                if ($key->getType() == XMLDB_KEY_PRIMARY) {
                    continue; // primary key is dealt with as id
                }
                if ($key->getType() == XMLDB_KEY_UNIQUE) {
                    $indexes[$key->getName()] = ['columns' => $key->getFields()];
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

            $definitions[$table->getName()]['id'] = [$idfield->getName() => $this->field_definition($idfield)];

            $fields = [];
            foreach ($table->getFields() as $field) {
                if ($field->getName() == $idfield->getName()) {
                    continue;
                }

                if (in_array($field->getName(), $ignorefields)) {
                    continue;
                }

                $fields[$field->getName()] = $this->field_definition($field);
            }

            $definitions[$table->getName()]['fields'] = $fields;

        } // end of first pass

        foreach ($joins as $table => $data) {
            foreach ($data as $reftable => $keys) {

            	if ($table === $reftable) {
            		// TODO: Support self-referential joins
            		continue;
            	}

            	foreach ($keys as $key) {
	                // TODO: Support composite keys?
	                $refField = implode('', $key->getRefFields());
	                $field = implode('', $key->getFields());
	                if (count($keys) == 1) {
	                    $manyToOneName = $table;
	                    $oneToManyName = $reftable;
	                } else {
	                    $manyToOneName = "{$table}_as_" . preg_replace('/_?id$/', '', $field);
	                    $oneToManyName = "{$reftable}_as_" . preg_replace('/_?id$/', '', $refField);
	                }

	                $otm = [
	                    'targetEntity' => self::classnameForTable($reftable),
	                    'mappedBy' => $field
	                ];

	                if (!isset($definitions[$table]['oneToMany'])) {
	                    $definitions[$table]['oneToMany'] = [];
	                }
	                $definitions[$table]['oneToMany'][$oneToManyName] = $otm;

	                $mto = [
	                    'targetEntity' => self::classnameForTable($table),
	                    'inversedBy' => $oneToManyName,
	                    'joinColumn' => [
	                        'name' => $refField,
	                        'referencedColumnId' => $field
	                    ]
	                ];

	                if (!isset($definitions[$reftable]['manyToOne'])) {
	                    $definitions[$reftable]['manyToOne'] = [];
	                }
	                $definitions[$reftable]['manyToOne'][$manyToOneName] = $mto;
            	}
            }
        }

        foreach ($definitions as $tablename => $definition) {
            // Write YAML mapping file
            $mapping = Yaml::dump([self::classnameForTable($tablename) => $definition], 6, 2);
            $out = fopen($this->mappingsdir . DIRECTORY_SEPARATOR . self::filenameForTable($tablename), "w");
            fputs($out, $mapping);
            fclose($out);
        }

        // Generate metadata from YAML
        $driver = new YamlDriver([$this->mappingsdir]);

        $metadatas = [];
        $f = new DisconnectedClassMetadataFactory();

        foreach ($driver->getAllClassNames() as $c) {
            $metadata = new ClassMetadata($c);
            $driver->loadMetadataForClass($c, $metadata);
            $metadatas[] = $metadata;
        }

        $destpath = __DIR__ . '/../temp';
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
        $classesdir = $CFG->dirroot.'/local/doctrine/classes/Entity';
        if (file_exists($classesdir)) {
            rmdir($classesdir);
        }
        rename($destpath . '/local_doctrine/Entity', $classesdir);
        rmdir($destpath . '/local_doctrine');
        rmdir($destpath);
    }

    function field_definition(\xmldb_field $field) {
        $result = [];
        $options = [];

        if ($field->getSequence()) {
            $result['generator'] = ['strategy' => 'auto'];
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

    public static function filenameForTable($table) {
        return str_replace('\\', '.', self::classnameForTable($table)) . '.dcm.yml';
    }

    public static function classnameForTable($table) {
        $targetnamespaceparts = explode('\\', self::TARGET_NAMESPACE);
        $nameparts = explode('_', $table);
        $namepartsuc = array_map(function($part) {
            if (ReservedWords::isReserved($part)) {
                $part = $part . 'X';
            }
            return ucfirst($part);
        }, $nameparts);

        return implode('\\', array_merge($targetnamespaceparts, $namepartsuc));
    }

    /**
     * Calculate the name for a oneToMany / manyToOne join.
     *
     * This will normally be simply the name of the ref table, but where
     * there is more than one link between the same table and ref table,
     * the ref field will be appended to distinguish.
     *
     * @param string $table
     * @param string $reftable
     * @param array $joins
     */
    public function joinName($table, $reftable, $reffield, $joins) {
		if ($joins[$table][$reftable] == 1) {
			return $reftable;
		} else {
			return $reftable . '_where_' . $reffield;
		}
    }

}


