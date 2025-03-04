<?php

namespace HalloWelt\MigrateRedmineWiki\Analyzer;

use HalloWelt\MediaWiki\Lib\Migration\Analyzer\SqlBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\SqlConnection;
use HalloWelt\MediaWiki\Lib\Migration\TitleBuilder;
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
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		// should find connection.json under input path
		if ( $file->getFilename() !== 'connection.json' ) {
			return true;
		}
		$connection = new SqlConnection( $file );
		$this->analyzePages( $connection );
		// analyze revesions
		// add redirect targets
		// analyze attachments

		return true;
	}

	/**
	 * ( not using $connection->getTables() names )
	 * ( not yet support database name prefix )
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzePages( $connection ) {
		$wikiIDtoName = $this->dataBuckets
			->getBucketData( 'initial-data' )['initial-data'][0];
		$res = $connection->query(
			"SELECT p.wiki_id, project_id, c.page_id, title, parent_id, "
			. "c.id AS content_id, c.version, protected FROM wikis w "
			. "INNER JOIN wiki_pages p ON w.id = p.wiki_id "
			. "INNER JOIN wiki_contents c ON p.id = c.page_id "
			. "WHERE w.status = 1 AND w.id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		$rows = [];
		while ( true ) {
			$row = mysqli_fetch_assoc( $res );
			if ( $row === null ) {
				break;
			}
			// use page id as index
			$rows[$row['page_id']] = $row;
			unset( $rows[$row['page_id']]['page_id'] );
		}

		foreach ( array_keys( $rows ) as $page_id ) {
			$titleBuilder = new TitleBuilder( [] );
			// assume that the migrated pages go to the default namespace
			$builder = $titleBuilder->setNamespace( 0 );
			$page = $page_id;
			while ( true ) {
				$row = $rows[$page];
				if ( $row['parent_id'] === null ) {
					if ( isset( $wikiIDtoName[$row['wiki_id']] ) ) {
						// assuming that initial data provide unique names
						$builder = $builder->appendTitleSegment(
							$wikiIDtoName[$row['wiki_id']]
						);
					} else {
						// workaround to make root titles unique
						$rootTitle = $row['project_id'] . "_" . $row['title'];
						$builder = $builder->appendTitleSegment( $rootTitle );
					}
					break;
				}
				$builder = $builder->appendTitleSegment( $row['title'] );
				$page = $row['parent_id'];
			}
			$rows[$page_id]['formatted_title'] = $builder->invertTitleSegments()->build();
		}
		// redirect target only makes sense after having processed page titles.
		// redirect target should be processed together with revisions
		// Page titles starting with "µ" are converted to capital "Μ" but not "M" in MediaWiki
		// should add statistics for cli output
		$this->dataBuckets->addData( 'wiki-page-map', 'wiki-page-map', $rows, true, false );
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
