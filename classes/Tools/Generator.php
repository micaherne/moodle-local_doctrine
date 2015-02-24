<?php

namespace local_doctrine\Tools;

use Symfony\Component\Yaml\Yaml;

use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\DisconnectedClassMetadataFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\EntityGenerator;
use Doctrine\Common\Inflector\Inflector;
use Symfony\Component\Filesystem\Filesystem;

class Generator {

    public $mappingsdir;
    const TARGET_NAMESPACE = 'local_doctrine\\Entity';

    function generateMappings($pluginname, $mappingsdir) {
        global $CFG, $DB;

        $db = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $dbman = $db->get_manager();

        if ($pluginname == 'core') {
        	$schema = $dbman->get_install_xml_schema();
        } else {
			$schema = $this->get_plugin_schema($pluginname);
        }


        $definitions = [];
        $fk = []; // tables with foreign key link to another $fk[many table][one table][fk]
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
                    if (!isset($fk[$tablename]) && !isset($fk[$tablename][$reftable])) {
                    	$fk[$tablename] = [$reftable => [$key]];
                    } else {
                        $fk[$tablename][$reftable][] = $key;
                    }

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

        foreach ($fk as $manytable => $data) {
            foreach ($data as $onetable => $keys) {

            	if ($manytable === $onetable) {
            		// TODO: Support self-referential joins
            		continue;
            	}

            	foreach ($keys as $key) {
	                // TODO: Support composite keys?
	                $onefield = implode('', $key->getRefFields()); // usually id
	                $manyfield = implode('', $key->getFields());

	                if (count($keys) == 1) {
	                    $oneToManyName = Inflector::pluralize($manytable);
	                    $manyToOneName = $onetable;
	                } else {
	                    $oneToManyName = Inflector::pluralize($manytable) . "_as_" . self::stripIdFromEnd($manyfield);
	                    $manyToOneName = "{$onetable}_as_" . self::stripIdFromEnd($manyfield);
	                }

	                // Stop doctrine croaking on join with same name as field
	                if (isset($definitions[$onetable]['fields'][$oneToManyName])) {
	                	$oneToManyName .= 'x'; // TODO: Must be something better than this!
	                }
	                if (isset($definitions[$manytable]['fields'][$manyToOneName])) {
	                	$manyToOneName .= 'x'; // TODO: Must be something better than this!
	                }

	                $otm = [
	                    'targetEntity' => self::classnameForTable($manytable),
	                    'mappedBy' => $manyToOneName
	                ];

	                if (!isset($definitions[$onetable]['oneToMany'])) {
	                    $definitions[$onetable]['oneToMany'] = [];
	                }
	                $definitions[$onetable]['oneToMany'][$oneToManyName] = $otm;

	                $mto = [
	                    'targetEntity' => self::classnameForTable($onetable),
	                    'inversedBy' => $oneToManyName,
	                    'joinColumn' => [
	                        'name' => $manyfield,
	                        'referencedColumnId' => $onefield
	                    ]
	                ];

	                if (!isset($definitions[$manytable]['manyToOne'])) {
	                    $definitions[$manytable]['manyToOne'] = [];
	                }
	                $definitions[$manytable]['manyToOne'][$manyToOneName] = $mto;
            	}
            }
        }

        foreach ($definitions as $tablename => $definition) {
            // Write YAML mapping file
            $mapping = Yaml::dump([self::classnameForTable($tablename) => $definition], 6, 2);
            $out = fopen($mappingsdir . DIRECTORY_SEPARATOR . self::filenameForTable($tablename), "w");
            fputs($out, $mapping);
            fclose($out);
        }

    }

    public function generateEntities($mappingsdir, $pluginname = 'core', $namespace = null, $extendclass = null) {

    	$plugindir = \core_component::get_component_directory($pluginname);
    	if ($pluginname == 'core') {
    		$plugindir = \core_component::get_component_directory('local_doctrine');
    	}

    	$classesdir = $plugindir . DIRECTORY_SEPARATOR . 'classes/Entity';
		if (!is_null($namespace)) {
    		if (strpos($namespace, '\\') === 0) {
    			$namespace = substr($namespace, 1);
    		}
    		$namespaceparts = explode('\\', $namespace);
    		$plugin = array_shift($namespaceparts);
    		$nsplugindir = \core_component::get_component_directory($plugin);
    		if (is_null($nsplugindir)) {
    			throw new Exception("Can't find plugin for namespace $namespace");
    		}
    		$classesdir = $nsplugindir . DIRECTORY_SEPARATOR . 'classes/' . implode('/', $namespaceparts);
		}

    	// Generate metadata from YAML
    	$driver = new YamlDriver([$mappingsdir]);

    	$metadatas = [];
    	$f = new DisconnectedClassMetadataFactory();

    	foreach ($driver->getAllClassNames() as $c) {
    		$metadata = new ClassMetadata($c);
    		$driver->loadMetadataForClass($c, $metadata);
    		$metadatas[] = $metadata;
    	}

    	// Generate entity classes
    	if (count($metadatas)) {
    		// Create EntityGenerator
    		$entityGenerator = new EntityGenerator();

    		$entityGenerator->setGenerateAnnotations(true);
    		$entityGenerator->setGenerateStubMethods(true);
    		$entityGenerator->setRegenerateEntityIfExists(true);
    		$entityGenerator->setUpdateEntityIfExists(true);
    		$entityGenerator->setNumSpaces(4);

    		if ($extendclass !== null) {
    			$entityGenerator->setClassToExtend($extendclass);
    		}

    		foreach ($metadatas as $metadata) {
    			mtrace(
    			sprintf('Processing entity "<info>%s</info>"', $metadata->name)
    			);
    		}


    		$destpath = $this->create_temp_dir();

    		// Generating Entities
    		$entityGenerator->generate($metadatas, $destpath);

    		// Outputting information message
    		mtrace(PHP_EOL . sprintf('Entity classes generated to "<info>%s</INFO>"', $destpath));
    	} else {
    		mtrace('No Metadata Classes to process.');
    	}

    	// Move to classes folder for autoloading
    	if (file_exists($classesdir)) {
    		rmdir($classesdir);
    	}

		$fs = new Filesystem();
    	rename($destpath . '/local_doctrine/Entity', $classesdir);
    	$fs->remove($destpath . '/local_doctrine');
    	$fs->remove($destpath);
    }

    public function create_temp_dir() {
    	$fs = new Filesystem();

    	$dir = tempnam(sys_get_temp_dir(), 'doc');
    	$fs->remove($dir);
    	$fs->mkdir($dir);

    	return $dir;
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
     * If the fieldname ends with id (or _id), strip this off.
     *
     * @param string $fieldname
     * @return string fieldname with id stripped
     */
    public static function stripIdFromEnd($fieldname) {
    	$pattern = '/_?id$/';
    	if (preg_match($pattern, $fieldname)) {
    		return preg_replace($pattern, '', $fieldname);
    	} else {
    		return $fieldname;
    	}
    }

    public function get_plugin_schema($pluginname) {
    	global $CFG;

    	$plugindir = \core_component::get_component_directory($pluginname);
    	if (is_null($plugindir)) {
    		throw new Exception("Plugin not found: $pluginname");
    	}
    	$dbdir = $plugindir . '/db';
    	$xmldb_file = new \xmldb_file($dbdir.'/install.xml');
    	if (!$xmldb_file->fileExists() or !$xmldb_file->loadXMLStructure()) {
    		throw new Exception("install.xml not found: $pluginname");
    	}
    	$schema = new \xmldb_structure('export');
    	$schema->setVersion($CFG->version);
    	$structure = $xmldb_file->getStructure();
    	$tables = $structure->getTables();
    	foreach ($tables as $table) {
    		$table->setPrevious(null);
    		$table->setNext(null);
    		$schema->addTable($table);
    	}
    	return $schema;
    }

}


