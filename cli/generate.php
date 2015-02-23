<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

define('CLI_SCRIPT', 1);
require_once (__DIR__.'/../../../config.php');

class GenerateMappings extends Command {

    protected function configure() {
        $this->setName('generate:mappings')
             ->setDescription('Generates Doctrine mappings from XMLDB definitions')
             ->addArgument('pluginname', InputArgument::OPTIONAL, 'The frankenstyle plugin name to generate ORM details for (omit for core)', 'core')
             ->addOption('mappingdir', 'm', InputOption::VALUE_OPTIONAL, 'The directory to create the mappings in.');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $plugindir = core_component::get_component_directory($input->getArgument('pluginname'));
        echo $plugindir;
        // TODO: finish
    }

}

$app = new Application('local_doctrine');
$app->add(new GenerateMappings());
$app->run();