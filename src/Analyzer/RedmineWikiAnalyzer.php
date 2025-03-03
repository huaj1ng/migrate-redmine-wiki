<?php

namespace HalloWelt\MigrateRedmineWiki\Analyzer;

use HalloWelt\MediaWiki\Lib\Migration\Analyzer\SqlBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\SqlConnection;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateRedmineWiki\ISourcePathAwareInterface;
use SplFileInfo;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

class RedmineWikiAnalyzer extends SqlBase implements IAnalyzer, IOutputAwareInterface, ISourcePathAwareInterface {

	/** @var DataBuckets */
	private $dataBuckets = null;

	/** @var Input */
	private $input = null;

	/** @var Output */
	private $output = null;

	/** @var string */
	private $src = '';

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		parent::__construct( $config, $workspace, $buckets );
		$this->dataBuckets = new DataBuckets( [
			'initial-data',
			'test-map',
			'wiki-page-map'
		] );
	}

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 * @return RedmineWikiAnalyzer
	 */
	public static function factory( $config, Workspace $workspace, DataBuckets $buckets ): RedmineWikiAnalyzer {
		return new static( $config, $workspace, $buckets );
	}

	/**
	 * @param Input $input
	 */
	public function setInput( Input $input ) {
		$this->input = $input;
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @param string $path
	 * @return void
	 */
	public function setSourcePath( $path ) {
		$this->src = $path;
	}

	/**
	 * @param SplFileInfo $file
	 * @return bool
	 */
	public function analyze( SplFileInfo $file ): bool {
		// $this->buckets->loadFromWorkspace( $this->workspace );
		$this->dataBuckets->loadFromWorkspace( $this->workspace );
		$result = parent::analyze( $file );

		// $this->buckets->saveToWorkspace( $this->workspace );
		$this->dataBuckets->saveToWorkspace( $this->workspace );
		return $result;
	}

	/**
	 * @param SplFileInfo $file
	 * @return bool
	 * @throws \Exception
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		if ( $file->getFilename() !== 'connection.json' ) {
			return true;
		}
		$connection = new SqlConnection( $file );
		// $tables = $connection->getTables();
		// ignoring listed table names for now
		$res = $connection->query(
			"SELECT p.wiki_id, project_id, c.page_id, title, parent_id, c.id as content_id, c.version FROM wikis w "
			. "inner join wiki_pages p on w.id = p.wiki_id "
			. "inner join wiki_contents c on p.id = c.page_id "
			. "where w.status = 1 and w.id in (10, 74, 57, 170); "
		);
		if ( $res === null ) {
			throw new \Exception( "\nFailed to run query!\n" );
		}

		// echo "\nActual data:\n";
		$rows = [];
		while ( true ) {
			$row = mysqli_fetch_assoc( $res );
			if ( $row === null ) {
				break;
			}
			$rows[] = $row;
		}

		$this->dataBuckets->addData( 'wiki-page-map', 'wiki-page-map', $rows, true, false );
		// $this->dataBuckets->addData( 'test-map', 'test-map', $rows, true, false );

		/*
		foreach ( $connection->getTables() as $table ) {
			if ( !$this->analyzeTable( $connection, $table ) ) {
				return false;
			}
		}
		*/
		return true;
	}

	/**
	 * @param array|null $row
	 * @param string $table
	 * @return bool
	 */
	protected function analyzeRow( $row, $table ) {
		return true;
	}
}
