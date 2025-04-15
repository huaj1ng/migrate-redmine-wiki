<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output\OutputInterface;

abstract class SimpleCommand extends Command {

	/** @var array */
	protected $config = [];

	/** @var Input\InputInterface */
	protected $input = null;

	/** @var OutputInterface */
	protected $output = null;

	/** @var string */
	protected $src = '';

	/** @var string */
	protected $dest = '';

	/** @var SplFileInfo */
	protected $currentFile = null;

	/** @var Workspace */
	protected $workspace = null;

	/** @var DataBuckets */
	protected $buckets = null;

	/** @param array $config */
	public function __construct( $config ) {
		parent::__construct();
		$this->config = $config;
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this->setDefinition( new Input\InputDefinition( [
			new Input\InputOption(
				'src',
				null,
				Input\InputOption::VALUE_REQUIRED,
				'Specifies the path to the input file or directory'
			),
			new Input\InputOption(
				'dest',
				null,
				Input\InputOption::VALUE_OPTIONAL,
				'Specifies the path to the outputfile or  directory',
				'.'
			)
		] ) );
	}

	/**
	 * @param Input\InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute( Input\InputInterface $input, OutputInterface $output ): int {
		$this->input = $input;
		$this->src = realpath( $this->input->getOption( 'src' ) );
		$this->dest = realpath( $this->input->getOption( 'dest' ) );
		$this->output = $output;
		$this->output->writeln( "Source: {$this->src}" );
		$this->currentFile = new SplFileInfo( $this->src );
		$this->output->writeln( "Destination: {$this->dest}\n" );
		$this->workspace = new Workspace( new SplFileInfo( $this->dest ) );
		$this->buckets = new DataBuckets( $this->getBucketKeys() );
		$this->buckets->loadFromWorkspace( $this->workspace );
		$returnValue = $this->process();
		$this->buckets->saveToWorkspace( $this->workspace );
		$this->output->writeln( '<info>Done.</info>' );
		return $returnValue;
	}

	/**
	 * @return array
	 */
	protected function getBucketKeys() {
		return [];
	}

	/**
	 * @return int
	 */
	abstract protected function process(): int;

	/**
	 * @param array $config
	 * @return static
	 */
	public static function factory( $config ) {
		return new static( $config );
	}
}
