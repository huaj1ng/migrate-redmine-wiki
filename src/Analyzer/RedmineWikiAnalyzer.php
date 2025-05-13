<?php

namespace HalloWelt\MigrateRedmineWiki\Analyzer;

use HalloWelt\MediaWiki\Lib\Migration\Analyzer\SqlBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MediaWiki\Lib\Migration\SqlConnection;
use HalloWelt\MediaWiki\Lib\Migration\TitleBuilder;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use SplFileInfo;
use Symfony\Component\Console\Output\Output;

class RedmineWikiAnalyzer extends SqlBase implements
	IAnalyzer,
	IOutputAwareInterface
{
	/** @var Output */
	private $output = null;

	/** @var DataBuckets */
	private $customBuckets = null;

	/** @var array */
	private $customizations = [];

	/** @var array */
	private $wikiNames = [];

	/** @var array */
	private $userNames = [];

	/** @var array */
	private $diagramIds = [];

	/** @var array */
	private $wantedAttachmentRevisions = [];

	/** @var array */
	private $wantedAttachmentIds = [];

	/** @var int */
	private $maintenanceUserID = 1;

	private const INT_MAX = 2147483647;

	/**
	 *
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		$this->config = $config;
		$this->workspace = $workspace;
		$this->buckets = $buckets;
		$this->customBuckets = new DataBuckets( [
			'customizations',
		] );
		$this->customBuckets->loadFromWorkspace( $this->workspace );
		$customizations = $this->customBuckets->getBucketData( 'customizations' );
		if ( !isset( $customizations['is-enabled'] ) || $customizations['is-enabled'] !== true ) {
			print_r( "No customization enabled\n" );
			$customizations = [];
			$customizations['is-enabled'] = false;
		} else {
			print_r( "Customizations loaded\n" );
		}
		$this->customizations = $customizations;
	}

	/**
	 * @param Output $output
	 */
	public function setOutput( Output $output ) {
		$this->output = $output;
	}

	/**
	 * @param SqlConnection $connection
	 * @return void
	 */
	protected function setNames( $connection ) {
		$res = $connection->query(
			"SELECT projects.id AS project_id, `name`, identifier, "
			. "wikis.id AS wiki_id FROM projects INNER JOIN wikis "
			. "ON projects.id = wikis.project_id;"
		);
		foreach ( $res as $row ) {
			$this->wikiNames[$row['wiki_id']] = $row['name'];
			// not fully used yet
		}

		$res = $connection->query(
			"SELECT id, login, firstname, lastname FROM users;"
		);
		foreach ( $res as $row ) {
			$fullName = trim( $row['firstname'] . ' ' . $row['lastname'] );
			$this->userNames[$row['id']] = $row['login']
				? $row['login']
				: ( strlen( $fullName ) > 0 ? $fullName : 'User ' . $row['id'] );
		}
	}

	/**
	 * @param int $id
	 * @return string
	 */
	protected function getUserName( $id ) {
		if ( isset( $this->userNames[$id] ) ) {
			return $this->userNames[$id];
		}
		print_r( "User ID " . $id . " not found in userNames\n" );
		return $id;
	}

	/**
	 *
	 * @param SplFileInfo $file
	 * @return bool
	 */
	protected function doAnalyze( SplFileInfo $file ): bool {
		if ( $file->getFilename() !== 'connection.json' ) {
			print_r( "Please use a connection.json!" );
			return true;
		}
		$connection = new SqlConnection( $file );
		$this->setNames( $connection );
		// wiki names not sufficiently used
		#$this->analyzeCategories( $connection );
		$this->analyzePages( $connection );
		$this->analyzeRevisions( $connection );
		$this->analyzeDiagrams( $connection );
		$this->analyzeRedirects( $connection );
		$this->analyzeAttachments( $connection );
		$this->doStatistics( $connection );
		// add symphony console output

		return true;
	}

	/**
	 * Analyze existing wiki pages and generate info wiki-pages array
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzePages( $connection ) {
		$customizations = $this->customizations;
		$wikiIDtoName = $this->wikiNames;
		$res = $connection->query(
			"SELECT p.wiki_id, project_id, c.page_id, title, p.parent_id, "
			. "pr.name AS project_name, pr.identifier AS project_identifier, "
			. "c.id AS content_id, c.version, protected FROM wikis w "
			. "INNER JOIN wiki_pages p ON w.id = p.wiki_id "
			. "INNER JOIN wiki_contents c ON p.id = c.page_id "
			. "INNER JOIN projects pr ON project_id = pr.id "
			. "WHERE w.status = 1 AND w.id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		$rows = [];
		foreach ( $res as $row ) {
			$rows[$row['page_id']] = $row;
			unset( $rows[$row['page_id']]['page_id'] );
		}
		foreach ( array_keys( $rows ) as $page_id ) {
			if ( $customizations['is-enabled'] && isset( $customizations['unwanted-projects'] ) ) {
				$unwantedProjects = explode( ', ', $customizations['unwanted-projects'] );
				if ( in_array( $rows[$page_id]['project_id'], $unwantedProjects ) ) {
					continue;
				}
			}
			$rows[$page_id]['categories'] = [];
			$titleBuilder = new TitleBuilder( [] );
			// assume that the migrated pages go to the default namespace
			$builder = $titleBuilder->setNamespace( 0 );
			$page = $page_id;
			while ( true ) {
				$row = $rows[$page];
				if ( $row['parent_id'] === null ) {
					$rootPageTitle = $row['project_id'] == $row['project_identifier']
						? $row['project_name']
						: $row['project_identifier'];
					$builder = $builder->appendTitleSegment( $row['title'] )
						->appendTitleSegment( $rootPageTitle );
					break;
				}
				$builder = $builder->appendTitleSegment( $row['title'] );
				$page = $row['parent_id'];
			}
			// naming convention: <root_page_title>/<root_page>/<sub_page>/..
			$fTitle = $builder->invertTitleSegments()->build();
			// handle U+00A0 (non-breaking space) in page titles
			$fTitle = str_replace( ' ', '_', $fTitle );
			$rows[$page_id]['formatted_title'] = $fTitle;
			if ( $customizations['is-enabled'] && isset( $customizations['categories-to-add'][$fTitle] ) ) {
				$rows[$page_id]['categories'] = array_unique( array_merge(
					$rows[$page_id]['categories'],
					$customizations['categories-to-add'][$fTitle]
				) );
			}
			if ( $customizations['is-enabled'] && isset( $customizations['pages-to-modify'][$fTitle] ) ) {
				if ( $customizations['pages-to-modify'][$fTitle] === false ) {
					continue;
				} else {
					$rows[$page_id]['formatted_title'] = $customizations['pages-to-modify'][$fTitle];
				}
			}
			$this->buckets->addData( 'wiki-pages', $page_id, $rows[$page_id], false, false );
		}
		// Page titles starting with "µ" are converted to capital "Μ" but not "M" in MediaWiki
	}

	/**
	 * Analyze revisions of wiki pages
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeRevisions( $connection ) {
		$customizations = $this->customizations;
		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		foreach ( array_keys( $wikiPages ) as $page_id ) {
			$res = $connection->query(
				"SELECT v.id AS rev_id, v.page_id, v.author_id, v.data, "
				. "v.comments, v.updated_on, v.version "
				. "FROM wiki_content_versions v "
				. "WHERE v.page_id = " . $page_id . " "
				. "ORDER BY v.version;"
			);
			// ORDER BY v.version is ascending by default, which is important
			$rows = [];
			$last_ver = null;
			foreach ( $res as $row ) {
				$ver = $row['version'];
				if ( $customizations['is-enabled'] && $customizations['current-revision-only'] ) {
					if ( $ver != $wikiPages[$page_id]['version'] ) {
						continue;
					}
				}
				$rows[$ver] = $row;
				unset( $rows[$ver]['version'] );
				$rows[$ver]['parent_rev_id'] = ( $last_ver !== null ) ?
					$rows[$last_ver]['rev_id']
					: null;
				$last_ver = $ver;
				$rows[$ver]['author_name'] = $this->getUserName( $row['author_id'] );
				$rows[$ver]['comments'] = '';
				$this->scanData( $rows[$ver]['data'] );
				if (
					is_array( $wikiPages[$page_id]['categories'] )
					&& count( $wikiPages[$page_id]['categories'] ) > 0
				) {
					$rows[$ver]['data'] .= "\n[[Category:"
						. implode( "]]\n[[Category:", $wikiPages[$page_id]['categories'] )
						. "]]";
				}
			}
			if ( count( $rows ) !== 0 ) {
				$this->buckets->addData( 'page-revisions', $page_id, $rows, false, false );
			}
		}
	}

	/**
	 * Scan and ananlyze revision text data
	 *
	 * @param string $data
	 * @return void
	 */
	protected function scanData( $data ) {
		preg_match_all( '/{{include_diagram\((\d+)(?:--.+?)\)}}/', $data, $matches );
		if ( !empty( $matches[1] ) ) {
			$this->diagramIds = array_unique( array_merge( $this->diagramIds, $matches[1] ) );
		}
		$customizations = $this->customizations;
		if ( $customizations['is-enabled'] && isset( $customizations['redmine-domain'] ) ) {
			$domain = $customizations['redmine-domain'];
			$pattern = '/https?:\/\/' . preg_quote( $domain, '/' ) . '\/attachments\/';
			$pattern .= '(?:(?:download|thumbnail)\/)?';
			$pattern .= '(\d+)';
			$pattern .= '([^"\'\s>\xa0]*)/u';
			preg_match_all( $pattern, $data, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				if ( !isset( $match[1] ) ) {
					continue;
				}
				$attachmentId = (int)$match[1];
				$urlSuffix = isset( $match[2] ) ? $match[2] : '';
				$isVersionSpecific = strpos( $urlSuffix, 'version=true' ) !== false;
				if ( $isVersionSpecific ) {
					$this->wantedAttachmentRevisions[] = $attachmentId;
				} else {
					$this->wantedAttachmentIds[] = $attachmentId;
				}
			}
		}
	}

	/**
	 * Analyze current version of diagrams
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeDiagrams( $connection ) {
		if ( count( $this->diagramIds ) === 0 ) {
			return;
		}
		$res = $connection->query(
			"SELECT d.id , d.title, d.current_position, d.project_id, "
			. "d.author_id, d.updated_at, d.html, d.xml_png FROM diagrams d "
			. "where d.id IN (" . implode( ", ", $this->diagramIds ) . ");"
		);
		foreach ( $res as $row ) {
			$id = $row['id'];
			unset( $row['id'] );
			$targetFilename = "Diagram" . $id . "--" . $row['title'] . '.png';
			$row['target_filename'] = $targetFilename;
			$titleBuilder = new TitleBuilder( [] );
			$fTitle = $titleBuilder
				->setNamespace( 6 )
				->appendTitleSegment( $targetFilename )
				->build();
			$fTitle = str_replace( ' ', '_', $fTitle );
			$row['formatted_title'] = $fTitle;
			$row['data_base64'] = str_replace( 'data:image/png;base64,', '', $row['xml_png'] );
			unset( $row['xml_png'] );
			$this->buckets->addData( 'diagram-contents', $id, $row, false, false );
		}
	}

	/**
	 * Generate revisions / pages and revisions for redirects
	 *
	 * @param SqlConnection $connection
	 */
	protected function analyzeRedirects( $connection ) {
		$customizations = $this->customizations;
		$wikiIDtoName = $this->wikiNames;
		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		print_r( "[wiki-pages] " . count( $wikiPages ) . " rows loaded by analyzeRedirects\n" );
		$pageRevisions = $this->buckets->getBucketData( 'page-revisions' );

		// Generate a revision for redirects
		// that correspond to an existing page
		$res = $connection->query(
			"SELECT p.id AS page_id, r.id AS redirect_id, "
			. "r.created_on, redirects_to_wiki_id, redirects_to, "
			. "w.project_id FROM wiki_redirects r "
			. "INNER JOIN wiki_pages p "
			. "ON r.wiki_id = p.wiki_id AND r.title = p.title "
			. "INNER JOIN wikis w ON r.wiki_id = w.id "
			. "WHERE r.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		$notes = [];
		$i = 0;
		foreach ( $res as $row ) {
			if ( $customizations['is-enabled'] && isset( $customizations['unwanted-projects'] ) ) {
				$unwantedProjects = explode( ', ', $customizations['unwanted-projects'] );
				if ( in_array( $row['project_id'], $unwantedProjects ) ) {
					continue;
				}
			}
			$id = $row['page_id'];
			$maxVersion = max( array_keys( $pageRevisions[$id] ) );
			$pageRevisions[$id][$maxVersion + 1] = [
				'rev_id' => self::INT_MAX - $i,
				'page_id' => $id,
				'author_id' => $this->maintenanceUserID,
				'author_name' => $this->getUserName( $this->maintenanceUserID ),
				'comments' => 'Migration-generated revision from redirects table',
				'updated_on' => $row['created_on'],
				'parent_rev_id' => $pageRevisions[$id][$maxVersion]['rev_id'],
			];
			$i++;
			$notes[$row['redirect_id']] = [
				'page_id' => $id,
				'generated_version_id' => $maxVersion + 1,
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
			. "pr.name AS project_name, pr.identifier AS project_identifier, "
			. "redirects_to_wiki_id, redirects_to FROM wiki_redirects r "
			. "INNER JOIN wikis w ON r.wiki_id = w.id "
			. "INNER JOIN projects pr ON w.project_id = pr.id "
			. "WHERE r.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . ") "
			. $additionalClause
			. "; "
		);
		foreach ( $res as $row ) {
			if ( $customizations['is-enabled'] && isset( $customizations['unwanted-projects'] ) ) {
				$unwantedProjects = explode( ', ', $customizations['unwanted-projects'] );
				if ( in_array( $row['project_id'], $unwantedProjects ) ) {
					continue;
				}
			}
			$wikiID = $row['wiki_id'];
			$titleBuilder = new TitleBuilder( [] );
			$rootPageTitle = $row['project_id'] == $row['project_identifier']
				? $row['project_name']
				: $row['project_identifier'];
			// follow naming convention in analyzePages
			$fTitle = $titleBuilder
				->setNamespace( 0 )
				->appendTitleSegment( $rootPageTitle )
				->appendTitleSegment( $row['page_title'] )
				->build();
			$fTitle = str_replace( ' ', '_', $fTitle );
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
				'categories' => [],
			];
			$pageRevisions[$id][1] = [
				'rev_id' => $id,
				'page_id' => $id,
				'author_id' => $this->maintenanceUserID,
				'author_name' => $this->getUserName( $this->maintenanceUserID ),
				'comments' => 'Redmine-Wiki-Migration: generated revision from wiki_redirects table',
				'updated_on' => $row['created_on'],
				'parent_rev_id' => null,
			];
			$notes[$row['redirect_id']] = [
				'page_id' => $id,
				'generated_version_id' => 1,
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
			if ( !$res || $res->num_rows === 0 ) {
				print_r( "Redirect target page not found: " . $note['redir_page_title'] . "\n" );
				$id = $note['page_id'];
				unset( $wikiPages[$id] );
				continue;
			}
			foreach ( $res as $row ) {
				if ( !isset( $wikiPages[$row['redir_page_id']] ) ) {
					print_r( "Redirect target page not found: " . $note['redir_page_title'] . "\n" );
					$id = $note['page_id'];
					unset( $wikiPages[$id] );
					continue;
				}
				$redirTitle = addslashes(
					$wikiPages[$row['redir_page_id']]['formatted_title']
				);
				$id = $note['page_id'];
				$generatedVerId = $note['generated_version_id'];
				$pageRevisions[$id][$generatedVerId]['data'] = "#REDIRECT [["
					. $redirTitle . "]]";
				$this->buckets->addData( 'page-revisions', $id, $pageRevisions[$id], false, false );
				$wikiPages[$id]['redirects_to'] = $redirTitle;
			}
		}
		foreach ( array_keys( $wikiPages ) as $id ) {
			$fTitle = $wikiPages[$id]['formatted_title'];
			if ( $customizations['is-enabled'] && isset( $customizations['categories-to-add'][$fTitle] ) ) {
				$wikiPages[$id]['categories'] = array_unique( array_merge(
					$wikiPages[$id]['categories'],
					$customizations['categories-to-add'][$fTitle]
				) );
			}
			if ( $customizations['is-enabled'] && isset( $customizations['pages-to-modify'][$fTitle] ) ) {
				if ( $customizations['pages-to-modify'][$fTitle] === false ) {
					continue;
				} else {
					$wikiPages[$id]['formatted_title'] = $customizations['pages-to-modify'][$fTitle];
				}
			}
			$this->buckets->addData( 'wiki-pages', $id, $wikiPages[$id], false, false );
		}
		print_r( "[wiki-pages] " . count( $wikiPages ) . " rows injected by analyzeRedirects\n" );
	}

	/**
	 * Analyze attachments, table and files
	 *
	 * Generate revisions / pages and revisions for attachments
	 * @param SqlConnection $connection
	 */
	protected function analyzeAttachments( $connection ) {
		$res = $connection->query(
			"SELECT attachment_id, container_type, container_id, filename "
			. "FROM attachment_versions WHERE filename IN ("
				. "SELECT filename FROM attachment_versions "
				. "WHERE container_type IS NOT NULL "
				. "GROUP BY filename "
				. "HAVING COUNT(*) >= 2 "
			. ") AND container_type IS NOT NULL "
			. "ORDER BY filename;"
		);
		$samenameAttachments = [];
		foreach ( $res as $row ) {
			$filename = $row['filename'];
			unset( $row['filename'] );
			$id = $row['attachment_id'];
			unset( $row['attachment_id'] );
			$samenameAttachments[$filename][$id] = $row;
			$this->buckets->addData(
				'samename-attachments',
				$filename,
				$samenameAttachments[$filename],
				false,
				false
			);
		}

		$wikiIDtoName = $this->wikiNames;
		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		$pageRevisions = $this->buckets->getBucketData( 'page-revisions' );
		$rows = [];
		$res = $connection->query(
			"SELECT u.attachment_id, u.id AS revision_id, "
			. "u.version, u.author_id, u.created_on, u.updated_at, "
			. "u.description, u.filename, u.disk_directory, u.disk_filename, "
			. "wp.wiki_id, pr.id AS project_id, pr.name AS project_name, "
			. "pr.identifier AS project_identifier, "
			. "u.content_type, u.filesize, u.digest, u.container_id "
			. "FROM attachment_versions u "
			. "INNER JOIN attachments a ON a.id = u.attachment_id "
			. "INNER JOIN wiki_pages wp ON u.container_id = wp.id "
			. "INNER JOIN wikis w ON wp.wiki_id = w.id "
			. "INNER JOIN projects pr ON w.project_id = pr.id "
			. "WHERE u.container_type = 'WikiPage' AND wp.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		foreach ( $res as $row ) {
			$pathPrefix = $row['disk_directory']
				? $row['disk_directory'] . DIRECTORY_SEPARATOR
				: '';
			$rows[$row['attachment_id']][$row['version']] = [
				'revision_id' => $row['revision_id'],
				'created_on' => $row['created_on'],
				'updated_at' => $row['updated_at'],
				'summary' => $row['description'],
				'user_id' => $row['author_id'],
				'filename' => $row['filename'],
				'source_path' => $pathPrefix . $row['disk_filename'],
				'target_filename' => isset( $samenameAttachments[$row['filename']] )
					? implode(
						'_',
						$samenameAttachments[$row['filename']][$row['attachment_id']]
					) . '_' . $row['filename']
					: $row['filename'],
				'quoted_page_id' => $row['container_id'],
				'wiki_id' => $row['wiki_id'],
				'project_id' => $row['project_id'],
				'project_name' => $row['project_name'],
				'project_identifier' => $row['project_identifier'],
			];
		}
		// when an attachment is no longer quoted in the current version
		// of a wiki page, its container type will switch to 'WikiContent'
		$res = $connection->query(
			"SELECT u.attachment_id, u.id AS revision_id, "
			. "u.version, u.author_id, u.created_on, u.updated_at, "
			. "u.description, u.filename, u.disk_directory, u.disk_filename, "
			. "u.content_type, u.filesize, u.digest, u.container_id "
			. "FROM attachment_versions u "
			. "INNER JOIN attachments a ON a.id = u.attachment_id "
			. "INNER JOIN wiki_contents c ON u.container_id = c.id "
			. "INNER JOIN wiki_pages wp ON c.page_id = wp.id "
			. "WHERE u.container_type = 'WikiContent' AND wp.wiki_id IN "
			. "(" . implode( ", ", array_keys( $wikiIDtoName ) ) . "); "
		);
		foreach ( $res as $row ) {
			$pathPrefix = $row['disk_directory']
				? $row['disk_directory'] . DIRECTORY_SEPARATOR
				: '';
			$rows[$row['attachment_id']][$row['version']] = [
				'revision_id' => $row['revision_id'],
				'created_on' => $row['created_on'],
				'updated_at' => $row['updated_at'],
				'summary' => $row['description'],
				'user_id' => $row['author_id'],
				'filename' => $row['filename'],
				'source_path' => $pathPrefix . $row['disk_filename'],
				'target_filename' => isset( $samenameAttachments[$row['filename']] )
					? implode(
						'_',
						$samenameAttachments[$row['filename']][$row['attachment_id']]
					) . '_' . $row['filename']
					: $row['filename'],
				'quoted_content_id' => $row['container_id'],
			];
		}
		// Include attachments from static links
		$this->wantedAttachmentRevisions = array_values(
			array_unique( $this->wantedAttachmentRevisions )
		);
		print_r( $this->wantedAttachmentRevisions );
		if ( count( $this->wantedAttachmentRevisions ) > 0 ) {
			$wantedAttachments = $this->wantedAttachmentRevisions;
			$res = $connection->query(
				"SELECT u.attachment_id, u.id AS revision_id, "
				. "u.version, u.author_id, u.created_on, u.updated_at, "
				. "u.description, u.filename, u.disk_directory, u.disk_filename, "
				. "u.content_type, u.filesize, u.digest, u.container_id "
				. "FROM attachment_versions u "
				. "WHERE u.id IN "
				. "(" . implode( ", ", $wantedAttachments ) . "); "
			);
			foreach ( $res as $row ) {
				$pathPrefix = $row['disk_directory']
					? $row['disk_directory'] . DIRECTORY_SEPARATOR
					: '';
				$rows[$row['attachment_id']][$row['version']] = [
					'revision_id' => $row['revision_id'],
					'created_on' => $row['created_on'],
					'updated_at' => $row['updated_at'],
					'summary' => $row['description'],
					'user_id' => $row['author_id'],
					'filename' => $row['filename'],
					'source_path' => $pathPrefix . $row['disk_filename'],
					'target_filename' => isset( $samenameAttachments[$row['filename']] )
						? implode(
							'_',
							$samenameAttachments[$row['filename']][$row['attachment_id']]
						) . '_' . $row['filename']
						: $row['filename'],
					'quoted_content_id' => $row['container_id'],
				];
			}
		}
		$this->wantedAttachmentIds = array_values(
			array_unique( $this->wantedAttachmentIds )
		);
		print_r( $this->wantedAttachmentIds );
		if ( count( $this->wantedAttachmentIds ) > 0 ) {
			$wantedAttachments = $this->wantedAttachmentIds;
			$res = $connection->query(
				"SELECT u.attachment_id, u.id AS revision_id, "
				. "u.version, u.author_id, u.created_on, u.updated_at, "
				. "u.description, u.filename, u.disk_directory, u.disk_filename, "
				. "u.content_type, u.filesize, u.digest, u.container_id "
				. "FROM attachment_versions u "
				. "WHERE u.attachment_id IN "
				. "(" . implode( ", ", $wantedAttachments ) . "); "
			);
			foreach ( $res as $row ) {
				$pathPrefix = $row['disk_directory']
					? $row['disk_directory'] . DIRECTORY_SEPARATOR
					: '';
				$rows[$row['attachment_id']][$row['version']] = [
					'revision_id' => $row['revision_id'],
					'created_on' => $row['created_on'],
					'updated_at' => $row['updated_at'],
					'summary' => $row['description'],
					'user_id' => $row['author_id'],
					'filename' => $row['filename'],
					'source_path' => $pathPrefix . $row['disk_filename'],
					'target_filename' => isset( $samenameAttachments[$row['filename']] )
						? implode(
							'_',
							$samenameAttachments[$row['filename']][$row['attachment_id']]
						) . '_' . $row['filename']
						: $row['filename'],
					'quoted_content_id' => $row['container_id'],
				];
			}
		}
		// generate a dummy page with a dummy revision for each attachment
		// the most important thing is the title
		$wikiPages = [];
		foreach ( array_keys( $rows ) as $id ) {
			// store attachment versions elsewhere to generate batch script
			$this->buckets->addData( 'attachment-files', $id, $rows[$id], false, false );

			$maxVersion = max( array_keys( $rows[$id] ) );
			$file = $rows[$id][$maxVersion];
			$titleBuilder = new TitleBuilder( [] );
			$fTitle = $titleBuilder
				->setNamespace( 6 )
				->appendTitleSegment( $file['target_filename'] )
				->build();
			$fTitle = str_replace( ' ', '_', $fTitle );
			$dummyId = $id + 1000000000;
			$wikiPages[$dummyId] = [
				'attachment_revision_id' => $file['revision_id'],
				'wiki_id' => $file['wiki_id'] ?? 0,
				'project_id' => $file['project_id'] ?? 0,
				'project_name' => $file['project_name'] ?? null,
				'project_identifier' => $file['project_identifier'] ?? null,
				'title' => $file['filename'],
				'parent_id' => null,
				'content_id' => $dummyId,
				'version' => 1,
				'protected' => 0,
				'formatted_title' => $fTitle,
				'categories' => [],
			];
			$pageRevision = [
				1 => [
					'rev_id' => $file['revision_id'],
					'page_id' => $dummyId,
					'author_name' => $this->getUserName( $file['user_id'] ),
					'author_id' => $file['user_id'],
					'data' => '',
					'comments' => 'Redmine-Wiki-Migration: generated revision from attachment_versions table.',
					'updated_on' => $file['updated_at'],
					'parent_rev_id' => null,
				],
			];
			$this->buckets->addData( 'page-revisions', $dummyId, $pageRevision, false, false );
		}
		foreach ( array_keys( $wikiPages ) as $id ) {
			$this->buckets->addData( 'wiki-pages', $id, $wikiPages[$id], false, false );
		}
	}

	/**
	 * Output statistics
	 *
	 * @param SqlConnection $connection
	 */
	protected function doStatistics( $connection ) {
		print_r( "\nstatistics:\n" );

		$wikiPages = $this->buckets->getBucketData( 'wiki-pages' );
		print_r( " - " . count( $wikiPages ) . " pages loaded\n" );

		$pageRevisions = $this->buckets->getBucketData( 'page-revisions' );
		$revCount = 0;
		foreach ( array_keys( $pageRevisions ) as $page_id ) {
			$revCount += count( $pageRevisions[$page_id] );
			foreach ( array_keys( $pageRevisions[$page_id] ) as $ver ) {
				if ( !isset( $pageRevisions[$page_id][$ver]['author_name'] ) ) {
					print_r( "author_name not set for page_id: " . $page_id . " ver: " . $ver . "\n" );
					var_dump( $pageRevisions[$page_id][$ver] );
				}
			}
		}
		print_r( " - " . $revCount . " page revisions loaded\n" );

		$attachmentFiles = $this->buckets->getBucketData( 'attachment-files' );
		print_r( " - " . count( $attachmentFiles ) . " attachments loaded\n" );
		$fileCount = 0;
		foreach ( array_keys( $attachmentFiles ) as $id ) {
			$fileCount += count( $attachmentFiles[$id] );
		}
		print_r( " - " . $fileCount . " attachment versions loaded\n" );
	}

	/**
	 * @inheritDoc
	 */
	protected function analyzeRow( $row, $table ) {
		return true;
	}
}
