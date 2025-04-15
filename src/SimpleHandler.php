<?php

namespace HalloWelt\MigrateRedmineWiki;

use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;
use Symfony\Component\Console\Output\Output;

abstract class SimpleHandler implements IOutputAwareInterface {

	/** @var array */
	protected $dataBucketList = [];

	/** @var array */
	protected $config = [];

	/** @var Workspace */
	protected $workspace = null;

	/** @var DataBuckets */
	protected $buckets = null;

	/** @var DataBuckets */
	protected $dataBuckets = null;

	/** @var SplFileInfo */
	protected $currentFile = null;

	/** @var Output */
	protected $output = null;

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		$this->config = $config;
		$this->workspace = $workspace;
		// These are the buckets to (over)write
		$this->buckets = $buckets;
		// These are the buckets to read from by default, though one can write to them manually
		$this->dataBuckets = new DataBuckets( $this->dataBucketList );
		$this->dataBuckets->loadFromWorkspace( $workspace );
	}

	/**
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 * @return static
	 */
	public static function factory( $config, Workspace $workspace, DataBuckets $buckets ) {
		return new static( $config, $workspace, $buckets );
	}
}
