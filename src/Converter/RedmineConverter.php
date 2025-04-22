<?php

namespace HalloWelt\MigrateRedmineWiki\Converter;

use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;
use HalloWelt\MigrateRedmineWiki\SimpleHandler;
use HalloWelt\MigrateRedmineWiki\Utility\ConvertToolbox;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class RedmineConverter extends SimpleHandler {

	/** @var array */
	protected $dataBucketList = [
		'wiki-pages',
		'page-revisions',
		'diagram-contents',
	];

	/** @var ConvertToolbox */
	protected $toolbox = null;

	/** @var int */
	protected $currentPageId = 0;

	/**
	 * @param array $config
	 * @param Workspace $workspace
	 * @param DataBuckets $buckets
	 */
	public function __construct( $config, Workspace $workspace, DataBuckets $buckets ) {
		parent::__construct( $config, $workspace, $buckets );
		$this->toolbox = new ConvertToolbox( $this->workspace );
	}

	/**
	 * @return bool
	 */
	public function convert(): bool {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$totalPages = count( $wikiPages );
		$output = new ConsoleOutput();
		$progressBar = new ProgressBar( $output, $totalPages );
		$progressBar->setFormat( ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' );
		$progressBar->start();

		$pageRevisions = $this->dataBuckets->getBucketData( 'page-revisions' );
		foreach ( $wikiPages as $id => $page ) {
			$result = [];
			foreach ( $pageRevisions[$id] as $version => $revision ) {
				$result[$version] = $this->doConvert( $revision['data'] );
			}
			$this->buckets->addData( 'revision-wikitext', $id, $result, false, false );
			$progressBar->advance();
		}
		$progressBar->finish();
		$output->writeln( "\n" );
		return true;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function doConvert( $content ) {
		$content = $this->preprocess( $content );
		$content = $this->processWithPandoc( $content, 'html', 'textile' );
		$content = $this->processWithPandoc( $content, 'textile', 'mediawiki' );
		$content = $this->handlePreTags( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function preprocess( $content ) {
		$content = preg_replace( '/<br \/>(?!\n)/', "<br />\n", $content );
		$content = $this->toolbox->replaceCustomized( $content );
		// $content = $this->toolbox->replaceInlineBeforePandoc( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @param string $source
	 * @param string $target
	 * @return string
	 * @phpcs:disable MediaWiki.Usage.ForbiddenFunctions.proc_open
	 */
	public function processWithPandoc( $content, $source, $target ) {
		$process = proc_open(
			"timeout 60 pandoc --from $source --to $target",
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		if ( $process === false ) {
			$this->output->writeln(
				"<error>Failed to start Pandoc process, "
				. "conversion from $source to $target skipped </error>"
			);
			return $content;
		}
		stream_set_blocking( $pipes[1], 0 );
		stream_set_blocking( $pipes[2], 0 );
		fwrite( $pipes[0], $content );
		fclose( $pipes[0] );

		$converted = '';
		$errors = '';
		$startTime = time();
		$timeout = 60;
		while ( time() - $startTime < $timeout ) {
			$converted .= stream_get_contents( $pipes[1] );
			$errors .= stream_get_contents( $pipes[2] );

			$status = proc_get_status( $process );
			if ( !$status['running'] ) {
				break;
			}
			usleep( 10000 );
		}
		$status = proc_get_status( $process );
		if ( $status['running'] ) {
			proc_terminate( $process, 9 );
			$this->output->writeln(
				"<error>Pandoc process timed out after $timeout seconds, content length: "
				. strlen( $content ) . " chars</error>"
			);
			print_r( $content );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			return "<!-- CONVERSION TIMEOUT: REQUIRES MANUAL REVIEW -->\n" . $content;
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exitCode = proc_close( $process );
		if ( $exitCode !== 0 && $exitCode !== null ) {
			$this->output->writeln(
				"<error>Conversion skipped: Pandoc failed with exit code $exitCode: $errors</error>"
			);
			return $content;
		}
		return $converted ?: $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handlePreTags( $content ) {
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceCollapsedBlocks( $content );
		$chunks = explode( "<pre>", $content );
		$chunks[0] = $this->postprocess( $chunks[0] );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( "</pre>", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$content .= $this->toolbox->convertCodeBlocks( $parts[0] );
					$content .= $this->postprocess( $parts[1] );
				} else {
					$content .= "<pre>" . $chunk;
				}
			}
		}
		$content = $this->handleHTMLTables( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function postprocess( $content ) {
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceInlineTitles( 'attachment:"', '"', '[[', ']]', $content );
		// should be replaced with better handling, resolving all #
		// can start when links are well wrapped
		$content = $this->toolbox->replaceInlineTitles( '\\#REDIREC', 'T', '#REDIREC', 'T', $content );
		// $content = preg_replace( '/\\\\#/', '#', $content );
		$content = $this->toolbox->replaceInlineElements( $content );
		$content = $this->handleDiagrams( $content );
		$content = $this->handleImages( $content );
		$content = $this->handleAnchors( $content );
		# $content = $this->handleEasyStoryLinks( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleHTMLTables( $content ) {
		$chunks = explode( '<figure class="table">', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( "</figure>", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$table = $this->processWithPandoc( $parts[0], 'html', 'mediawiki' );
					$table = preg_replace( '/\| \*/', "|\n*", $table );
					$content .= $table . $parts[1];
				} else {
					$content .= "<figure>" . $chunk;
				}
			}
		}
		$chunks = explode( '<table', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( ">", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</table>", $parts[1], 2 );
					$table = $this->processWithPandoc(
						"<table" . $parts[0] . ">" . $pieces[0] . "</table>",
						'html',
						'mediawiki'
					);
					$content .= preg_replace( '/\| \*/', "|\n*", $table );
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<table" . $chunk;
				}
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleDiagrams( $content ) {
		return preg_replace_callback(
			'/\{\{include_diagram\((\d+)--([^)]+)\)\}\}/i',
			function ( $matches ) {
				$diagrams = $this->dataBuckets->getBucketData( 'diagram-contents' );
				$diagram = $diagrams[$matches[1]];
				if ( !$diagram ) {
					return $matches[0];
				}
				$filename = preg_replace( '/\.png$/i', '', $diagram['target_filename'] );
				return "<drawio filename=\"$filename\" alt=\"$matches[2]\"></drawio>";
			},
			$content
		);
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleImages( $content ) {
		$chunks = explode( '<figure class="image', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( ">", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</figure>", $parts[1], 2 );
					$content .= $pieces[0];
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<figure" . $chunk;
				}
			}
		}
		$chunks = explode( '<img ', $content );
		$content = $chunks[0];
		$customizations = $this->toolbox->getCustomizations();
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( ">", $chunk, 2 );
				if ( count( $parts ) === 2 ) {
					$match = preg_match( '/src="([^"]+)"/', $parts[0], $matches );
					$content .= $match === false
						? "<img" . $chunk
						: ( strpos( $matches[1], ':/' ) === false
						? "[[" . $this->toolbox->getFormattedTitle(
							urldecode( $matches[1] )
						) . "]]" . $parts[1]
						: ( !isset( $customizations['redmine-domain'] )
							|| strpos( $matches[1], $customizations['redmine-domain'] ) === false
						? "[" . $matches[1] . "]" . $parts[1]
						: ( $this->toolbox->getAttachmentTitleFromLink( $matches[1] )
						? "[[" . $this->toolbox->getAttachmentTitleFromLink( $matches[1] ) . "]]" . $parts[1]
						: $matches[1] . "]" . $parts[1]
						) ) );
				} else {
					$content .= "<img" . $chunk;
				}
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function handleAnchors( $content ) {
		$chunks = explode( '<a ', $content );
		$content = $chunks[0];
		if ( count( $chunks ) > 1 ) {
			for ( $i = 1; $i < count( $chunks ); $i++ ) {
				$chunk = $chunks[$i];
				$parts = explode( '>', $chunk, 2 );
				$match = preg_match( '/href="([^"]+)"/', $parts[0], $matches );
				if ( $match ) {
					$link = $matches[1];
				} else {
					$link = "anchor-handle-error";
					print_r( "\nError occured when handling anchor href in: " . $chunk );
				}
				// point to check if the link is a local link: tba
				if ( count( $parts ) === 2 ) {
					$pieces = explode( "</a>", $parts[1], 2 );
					if ( !isset( $pieces[0] ) ) {
						$text = $link;
					} else {
						$text = preg_replace( '/<br \/>/', '', $pieces[0] );
						$text = preg_replace( '/\n/', '', $text );
						$text = preg_replace( '/\[\[/', '', $text );
						$text = preg_replace( '/\]\]/', '', $text );
						$text = trim( $text );
						if ( $text === '' ) {
							$text = $link;
						}
					}
					$content .= "[" . $link . " " . $text . "]";
					$content .= isset( $pieces[1] ) ? $pieces[1] : '';
				} else {
					$content .= "<a" . $chunk;
					print_r( "\nError occured when handling anchor in: " . $chunk );
				}
			}
		}
		return $content;
	}

	/**
	 * To be altered
	 *
	 * @param string $content
	 * @return string
	 */
	public function handleEasyStoryLinks( $content ) {
		$domain = $this->toolbox->getDomain();
		if ( !$domain ) {
			return $content;
		}
		// Pattern 1: [https://example.com/easy_knowledge_stories/123 some text]
		$content = preg_replace_callback(
			'/\[https?:\/\/' . $domain . '\/easy_knowledge_stories\/(\d+)(?:\?[^\s\]]*)?(\s+[^\]]+)?\]/i',
			function ( $matches ) {
				$id = (int)$matches[1];
				$text = isset( $matches[2] ) ? ltrim( $matches[2] ) : '';
				$title = $this->toolbox->getFormattedTitleFromId( $id ) ?? "EKBStory-$id";
				return $text !== '' ? "[[{$title}|{$text}]]" : "[[{$title}]]";
			},
			$content
		);
		// Pattern 2: https://example.com/easy_knowledge_stories/123
		$content = preg_replace_callback(
			'/(?<![[\w|])https?:\/\/' . $domain . '\/easy_knowledge_stories\/(\d+)(?:\?[^[\s]*)?(?![]\w])/i',
			function ( $matches ) {
				$id = (int)$matches[1];
				$title = $this->toolbox->getFormattedTitleFromId( $id ) ?? "EKBStory-$id";
				return "[[{$title}]]";
			},
			$content
		);
		return $content;
	}
}
