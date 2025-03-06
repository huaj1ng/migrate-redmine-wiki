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
		// not using $connection->getTables() names
		// not yet support database name prefix
		$this->analyzePages( $connection );
		$this->analyzeRevisions( $connection );
		$this->analyzeRedirects( $connection );
		$this->analyzeAttachments( $connection );
		// add symphony console output
		// add statistics

		return true;
	}

	/**
	 * Analyze existing wiki pages
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzePages( $connection ) {
		$wikiIDtoName = $this->dataBuckets
			->getBucketData( 'initial-data' )['wiki-id-name'][0];
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
	 * Analyze revisions of wiki pages
	 *
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
	 * Generate revisions / pages and revisions for redirects
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeRedirects( $connection ) {
		$wikiIDtoName = $this->dataBuckets
			->getBucketData( 'initial-data' )['wiki-id-name'][0];
		$wikiPages = $this->dataBuckets
			->getBucketData( 'wiki-pages' )['wiki-pages'];
		$pageRevisions = $this->dataBuckets
			->getBucketData( 'page-revisions' );

		// Generate a revision for redirects
		// that correspond to an existing page
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
			$maxVersion = max( array_keys( $pageRevisions[$id] ) );
			$pageRevisions[$id][$maxVersion + 1] = [
				'rev_id' => self::INT_MAX - $i,
				'page_id' => $id,
				'author_id' => 1,
				'comments' => 'Migration-generated revision from redirects table',
				'updated_on' => $row['created_on'],
				'parent_rev_id' => $pageRevisions[$id][$maxVersion]['rev_id'],
			];
			$i++;
			$notes[$row['redirect_id']] = [
				'page_id' => $id,
				'generated_rev_id' => $maxVersion + 1,
				'redir_wiki_id' => $row['redirects_to_wiki_id'],
				'redir_page_title' => $row['redirects_to'],
			];
		}

		// Generate a page and a revision for redirects
		// that do not correspond to an existing page
		$additionalClause = count( $notes ) > 0
			? "AND r.id NOT IN (" . implode( "', '", array_keys( $notes ) ) . ") "
			: "";
		$res = $connection->query(
			"SELECT w.id AS wiki_id, w.project_id, r.id AS redirect_id, "
			. "r.title AS page_title, r.created_on, "
			. "redirects_to_wiki_id, redirects_to "
			. "FROM wiki_redirects r INNER JOIN wikis w "
			. "ON r.wiki_id = w.id "
			. "WHERE r.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . ") "
			. $additionalClause
			. "; "
		);
		while ( true ) {
			$row = mysqli_fetch_assoc( $res );
			if ( $row === null ) {
				break;
			}
			$wikiID = $row['wiki_id'];
			$titleBuilder = new TitleBuilder( [] );
			$fTitle = $titleBuilder
				->setNamespace( 0 )
				->appendTitleSegment( $row['page_title'] )
				->build();
			$id = self::INT_MAX - $i;
			$i++;
			$wikiPages[$id] = [
				'wiki_id' => $row['wiki_id'],
				'project_id' => $row['project_id'],
				'title' => $row['page_title'],
				'parent_id' => null,
				'content_id' => $id,
				'version' => 1,
				'protected' => 0,
				'formatted_title' => $fTitle,
			];
			$pageRevisions[$id][1] = [
				'rev_id' => $id,
				'page_id' => $id,
				'author_id' => 1,
				'comments' => 'Migration-generated revision from redirects table',
				'updated_on' => $row['created_on'],
				'parent_rev_id' => null,
			];
			$notes[$row['redirect_id']] = [
				'page_id' => $id,
				'generated_rev_id' => $id,
				'redir_wiki_id' => $row['redirects_to_wiki_id'],
				'redir_page_title' => $row['redirects_to'],
			];
		}

		// Insert redirect target info to pages and revisions involved
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
	 * Analyze attachments, table and files
	 *
	 * Generate revisions / pages and revisions for redirects
	 * @param SqlConnection $connection
	 */
	protected function analyzeAttachments( $connection ) {
		$wikiIDtoName = $this->dataBuckets
			->getBucketData( 'initial-data' )['wiki-id-name'][0];
		$wikiPages = $this->dataBuckets
			->getBucketData( 'wiki-pages' )['wiki-pages'];
		$pageRevisions = $this->dataBuckets
			->getBucketData( 'page-revisions' );
		$commonClause = "SELECT u.attachment_id, u.id AS revision_id, "
			. "u.version, u.author_id, u.created_on, u.description, "
			. "u.filename, u.disk_directory, u.disk_filename, "
			. "u.content_type, u.filesize, u.digest, u.container_id "
			. "FROM attachment_versions u "
			. "INNER JOIN attachments a ON a.id = u.attachment_id ";
		// handle attachments for wiki pages
		$res = $connection->query(
			$commonClause
			. "INNER JOIN wiki_pages p ON u.container_id = p.id 
			WHERE u.container_type = 'WikiPage' AND p.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		// handle attachments for wiki contents
		$res = $connection->query(
			$commonClause
			. "INNER JOIN wiki_contents c ON u.container_id = c.id 
			INNER JOIN wiki_pages p ON c.page_id = p.id 
			WHERE u.container_type = 'WikiContent' AND p.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
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
