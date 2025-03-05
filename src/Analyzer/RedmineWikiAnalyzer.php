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

	private const INT_MAX = 2147483647;

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
			'wiki-pages',
			'page-revisions',
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
		$this->analyzeRevisions( $connection );
		$this->analyzeRedirectsWithPages( $connection );
		$this->analyzeRedirectsWithoutPages( $connection );
		// add symphony console output
		// add statistics
		// analyze attachments, table and files
		// move annotations here

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
					// not using $wikiIDtoName[$row['wiki_id']]
					$rootTitle = $row['title'] . "_" . $row['project_id'];
					$builder = $builder->appendTitleSegment( $rootTitle );
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
		$this->dataBuckets->addData( 'wiki-pages', 'wiki-pages', $rows, false, false );
	}

	/**
	 * ( ignored wiki_content_versions.compression )
	 * @param SqlConnection $connection
	 */
	protected function analyzeRevisions( $connection ) {
		$wikiPages = $this->dataBuckets
			->getBucketData( 'wiki-pages' )['wiki-pages'];
		foreach ( array_keys( $wikiPages ) as $page_id ) {
			$res = $connection->query(
				"SELECT v.id AS rev_id, v.page_id, v.author_id, v.data, "
				. "v.comments, v.updated_on, v.version "
				. "FROM wiki_content_versions v "
				// . "INNER JOIN users u ON c.author_id = u.id "
				. "WHERE v.page_id = " . $page_id . " "
				. "ORDER BY v.version;"
			);
			// ORDER BY v.version is ascending by default, which is important
			$rows = [];
			$last_ver = null;
			while ( true ) {
				$row = mysqli_fetch_assoc( $res );
				if ( $row === null ) {
					break;
				}
				$ver = $row['version'];
				$rows[$ver] = $row;
				unset( $rows[$ver]['version'] );
				$rows[$ver]['parent_rev_id'] = ( $last_ver !== null ) ?
					$rows[$last_ver]['rev_id']
					: null;
				$last_ver = $ver;
				if ( count( $rows ) === 0 ) {
					break;
				}
			}
			$this->dataBuckets->addData( 'page-revisions', $page_id, $rows, false, false );
		}
	}

	/**
	 * Generate a revision for redirects that correspond to an existing page
	 * @param SqlConnection $connection
	 */
	protected function analyzeRedirectsWithPages( $connection ) {
		$wikiIDtoName = $this->dataBuckets
			->getBucketData( 'initial-data' )['initial-data'][0];
		$wikiPages = $this->dataBuckets
			->getBucketData( 'wiki-pages' )['wiki-pages'];
		$pageRevisions = $this->dataBuckets
			->getBucketData( 'page-revisions' );
		// Handle when redirect source is an existing page
		// In this case, we only append a new revision
		$res = $connection->query(
			"SELECT p.id AS page_id, r.id AS redirect_id, "
			. "r.created_on, redirects_to_wiki_id, redirects_to "
			. "FROM wiki_redirects r INNER JOIN wiki_pages p "
			. "ON r.wiki_id = p.wiki_id AND r.title = p.title "
			. "WHERE r.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		$notes = [];
		$i = 0;
		while ( true ) {
			$row = mysqli_fetch_assoc( $res );
			if ( $row === null ) {
				break;
			}
			$id = $row['page_id'];
			$maxRevision = max( array_keys( $pageRevisions[$id] ) );
			$pageRevisions[$id][$maxRevision + 1] = [
				'rev_id' => self::INT_MAX - $i,
				'page_id' => $id,
				'author_id' => 1,
				'comments' => 'Migration-generated revision from redirects table',
				'updated_on' => $row['created_on'],
				'parent_rev_id' => $maxRevision,
			];
			$i++;
			$notes[] = [
				'page_id' => $id,
				'generated_rev_id' => $maxRevision + 1,
				'redir_wiki_id' => $row['redirects_to_wiki_id'],
				'redir_page_title' => $row['redirects_to'],
			];
		}
		foreach ( $notes as $note ) {
			$res = $connection->query(
				"SELECT id AS redir_page_id FROM wiki_pages "
				. "WHERE wiki_id = " . $note['redir_wiki_id'] . " "
				. "AND title = '" . $note['redir_page_title'] . "';"
			);
			while ( true ) {
				$row = mysqli_fetch_assoc( $res );
				if ( $row === null ) {
					break;
				}
				$redirTitle = addslashes(
					$wikiPages[$row['redir_page_id']]['formatted_title']
				);
				$id = $note['page_id'];
				$generatedRevId = $note['generated_rev_id'];
				$pageRevisions[$id][$generatedRevId]['data'] = "#REDIRECT [["
					. $redirTitle . "]]";
				$this->dataBuckets->addData( 'page-revisions', $id, $pageRevisions[$id], false, true );
				$wikiPages[$id]['redirects_to'] = $redirTitle;
			}
		}
		$this->dataBuckets->addData( 'wiki-pages', 'wiki-pages', $wikiPages, false, true );
	}

	/**
	 * Generate a page and a revision for pure redirects
	 * that do not correspond to an existing page
	 * @param SqlConnection $connection
	 */
	protected function analyzeRedirectsWithoutPages( $connection ) {
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
