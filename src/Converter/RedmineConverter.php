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
		'ekb_stories',
	];

	/** @var ConvertToolbox */
	protected $toolbox = null;

	/** @var int */
	protected $currentPageId = 0;

	/** @var int */
	protected $currentPageVersion = 0;

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
	 * @param int $pageId
	 * @param int $version
	 */
	public function setCurrentPage( $pageId, $version ) {
		$this->currentPageId = $pageId;
		$this->currentPageVersion = $version;
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
				$this->setCurrentPage( $id, $version );
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
		$content = preg_replace( '/([^\n])<\/li>/', "$1\n</li>", $content );
		$content = $this->processWithPandoc( $content, 'textile', 'mediawiki' );
		$content = $this->fixAfterPandoc( $content );
		$content = $this->handleCodeAndNonCode( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function fixAfterPandoc( $content ) {
		$content = preg_replace( '/(\n|^)\\\\#/', '$1#', $content );
		$content = preg_replace( '/(\n|^)\\\\\*/', '$1*', $content );
		// Handle escaped # and * in table cells based on what follows them
		// If followed by a space, insert a line break (for lists/headings)
		$content = preg_replace( '/(\|\s+)\\\\#(\s+)/', "$1\n#$2", $content );
		$content = preg_replace( '/(\|\s+)\\\\\*(\s+)/', "$1\n*$2", $content );
		// If NOT followed by a space (like filenames *.jpg), just remove the escape character
		$content = preg_replace( '/(\|\s+)\\\\#([^\s])/', "$1#$2", $content );
		$content = preg_replace( '/(\|\s+)\\\\\*([^\s])/', "$1*$2", $content );
		$content = $this->toolbox->replaceFirstLinesAfterPandoc( $content );
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceCollapsedBlocks( $content );
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function preprocess( $content ) {
		$content = preg_replace( '/<br \/>(?!\n)/', "<br />\n", $content );
		$content = preg_replace( '/<\/a>/', "</a> ", $content );
		$content = preg_replace( '/(?<!\n)<ul>/', "\n<ul>", $content );
		$content = preg_replace( '/(?<!\n)<table>/', "\n<table>", $content );
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
	 * Post process content without changing content wrapped
	 * by pre, syntaxhighlight and code tags
	 *
	 * @param string $content
	 * @return string
	 */
	public function handleCodeAndNonCode( $content ) {
		$blocks = [];
		$blockCount = 0;
		$content = preg_replace( '/<pre class="plaintext">/', '<pre>', $content );
		$content = preg_replace_callback( '/<pre>(.*?)<\/pre>/s', function ( $matches ) use ( &$blocks, &$blockCount ) {
			$placeholder = "##CODE_BLOCK_{$blockCount}##";
			$blocks[$blockCount] = [
				'type' => 'pre',
				'content' => $this->toolbox->replaceEncodedEntities( $matches[1] ),
			];
			$blockCount++;
			return $placeholder;
		}, $content );
		$content = preg_replace_callback(
			'/<syntaxhighlight\s+lang\s*=\s*["\'](.*?)["\']\s*>(.*?)<\/syntaxhighlight>/s',
			function ( $matches ) use ( &$blocks, &$blockCount ) {
				$placeholder = "##CODE_BLOCK_{$blockCount}##";
				$blocks[$blockCount] = [
					'type' => 'syntaxhighlight',
					'lang' => $matches[1],
					'content' => $this->toolbox->replaceEncodedEntities( $matches[2] ),
				];
				$blockCount++;
				return $placeholder;
			}, $content );
		$content = preg_replace_callback( '/<code>(.*?)<\/code>/s',
			function ( $matches ) use ( &$blocks, &$blockCount ) {
				$placeholder = "##CODE_BLOCK_{$blockCount}##";
				$blocks[$blockCount] = [
					'type' => 'code',
					'content' => $this->toolbox->replaceEncodedEntities( $matches[1] ),
				];
				$blockCount++;
				return $placeholder;
			}, $content );

		// Process the content outside code blocks
		$content = $this->postprocess( $content );
		$content = $this->handleHTMLTables( $content );

		return preg_replace_callback( '/##CODE_BLOCK_(\d+)##/', static function ( $matches ) use ( $blocks ) {
			$blockId = $matches[1];
			$block = $blocks[$blockId];
			switch ( $block['type'] ) {
				case 'pre':
					// return $this->toolbox->convertCodeBlocks($block['content']);
					return '<pre>' . $block['content'] . '</pre>';
				case 'syntaxhighlight':
					return '<syntaxhighlight lang="' . $block['lang'] . '">' . $block['content'] . '</syntaxhighlight>';
				case 'code':
					return '<code>' . $block['content'] . '</code>';
				default:
					return $matches[0];
			}
		}, $content );
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function postprocess( $content ) {
		$content = $this->toolbox->replaceEncodedEntities( $content );
		$content = $this->toolbox->replaceInlineTitles( 'attachment:"', '"', '[[', ']]', $content );
		$content = $this->toolbox->replaceInlineElements( $content );
		$content = $this->handleMacros( $content );
		$content = $this->handleImages( $content );
		$content = $this->handleAnchors( $content );
		$content = $this->preHandleUrls( $content );
		$id = $this->currentPageId;
		$version = $this->currentPageVersion;
		$content = $this->findLinks( $content, $id, $version );
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
					$table = $parts[0];
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
	public function handleMacros( $content ) {
		$content = preg_replace_callback(
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
		$content = preg_replace_callback(
			'/\{\{thumbnail\(([^,)]+)(?:,\s*(?:size=(\d+))?(?:,\s*title=([^)]+))?)?\)\}\}/i',
			static function ( $matches ) {
				$filename = trim( $matches[1] );
				$size = isset( $matches[2] ) ? trim( $matches[2] ) : null;
				$title = isset( $matches[3] ) ? trim( $matches[3] ) : null;
				return "[[File:" . $filename . "]]";
			},
			$content
		);
		$content = preg_replace_callback(
			'/\{\{issue\((\d+)(?:,\s*([^)]+))?\)\}\}/i',
			function ( $matches ) {
				$issueId = $matches[1];
				// Get domain from customizations or use the default
				$domain = $this->toolbox->getDomain();
				if ( !$domain ) {
					return $matches[0];
				}
				$domain = rtrim( $domain, '/' );
				return "[https://{$domain}/issues/{$issueId} #{$issueId}]";
			},
			$content
		);
		$content = preg_replace_callback(
			'/\{\{include\(([^)]+)\)\}\}/i',
			static function ( $matches ) {
				$pageName = trim( $matches[1] );
				return "{{:" . $pageName . "}}";
			},
			$content
		);
		$content = preg_replace_callback(
			'/\{\{child_pages(?:\(([^)]*)\))?\}\}/i',
			static function ( $matches ) {
				if ( empty( $matches[1] ) ) {
					return "{{#subpages:}}";
				}
				$parts = explode( ',', $matches[1] );
				$title = "";
				foreach ( $parts as $part ) {
					if ( strpos( $part, '=' ) === false ) {
						$title = $part;
						break;
					}
				}
				return "{{#subpages:" . $title . "}}";
			},
			$content
		);
		return $content;
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
		$content = preg_replace_callback(
			'/(?<=\n)!([^!\n]+?)!/',
			static function ( $matches ) {
				$filename = trim( $matches[1] );
				return "\n[[File:" . $filename . "]]";
			},
			$content
		);
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
	 * @param string $content
	 * @return string
	 */
	public function preHandleUrls( $content ) {
		$content = preg_replace_callback(
			'/\[\[([^\]]*:\/\/[^\]]*)\]\]/i',
			static function ( $matches ) {
				return '[' . str_replace( '|', ' ', $matches[1] ) . ']';
			},
			$content
		);
		// Fix misformatted links that start with File:https://
		$content = preg_replace_callback(
			'/\[File:(https?:\/\/[^\]\s]+)(?:\s+([^\]]+))?\]/i',
			static function ( $matches ) {
				$url = $matches[1];
				$linkText = isset( $matches[2] ) ? $matches[2] : '';
				return '[' . $url . ( $linkText ? ' ' . $linkText : '' ) . ']';
			},
			$content
		);
		$domain = $this->toolbox->getDomain();
		if ( !$domain ) {
			return $content;
		}
		// Process bracketed links [http://...]
		$content = preg_replace_callback(
			'/\[https?:\/\/([^\]\s]+)(?:\s+([^\]]+))?\]/i',
			function ( $matches ) use ( $domain ) {
				$wikiTitle = $this->correspondUrls( $matches[1], $domain );
				$linkText = isset( $matches[2] ) ? $matches[2] : '';
				if ( $wikiTitle ) {
					return $linkText ? "[[{$wikiTitle}|{$linkText}]]" : "[[{$wikiTitle}]]";
				}
				return $matches[0];
			},
			$content
		);
		// Process raw/unbracketed URLs
		$content = preg_replace_callback(
			'/(?<![[\w|"\'])https?:\/\/([^\s<>"\'\]\[]+)(?!["\'\w\]])/i',
			function ( $matches ) use ( $domain ) {
				$wikiTitle = $this->correspondUrls( $matches[1], $domain );
				if ( $wikiTitle ) {
					return "[[{$wikiTitle}]]";
				}
				return $matches[0];
			},
			$content
		);
		return $content;
	}

	/**
	 * Correspond full URLs to wiki pages or (Easy)Redmine functionalities
	 *
	 * @param string $url The URL to analyze
	 * @param string $domain The Redmine domain
	 * @return string|false The wiki page title if matched, false otherwise
	 */
	private function correspondUrls( $url, $domain ) {
		if ( strpos( $url, $domain ) === false ) {
			return false;
		}
		if ( preg_match( '/' . preg_quote( $domain, '/' ) . '\/projects\/([^?\s]+)/i', $url, $matches ) ) {
			$projectPath = $matches[1];
			$pos = strpos( $projectPath, '?' );
			if ( ( $pos ) !== false ) {
				$projectPath = substr( $projectPath, 0, $pos );
			}
			$parts = explode( '/', $projectPath );
			if ( count( $parts ) === 1 && strpos( $parts[0], ':' ) !== false ) {
				return $parts[0];
			} elseif ( count( $parts ) > 1 ) {
				if ( $parts[1] === 'issues' ) {
					return false;
				}
				return $parts[0] . ':' . array_pop( $parts );
			}
			// return $this->toolbox->getWikiPageTitle($projectPath);
			return false;
		}
		if ( preg_match( '/' . preg_quote( $domain, '/' ) . '\/attachments\/([^?\s]+)/i', $url, $matches ) ) {
			$title = $this->toolbox->getAttachmentTitleFromLink( "https://" . $matches[0] );
			return $title ?: false;
		}
		if ( preg_match( '/' . preg_quote( $domain, '/' ) . '\/easy_knowledge_stories\/(\d+)/i', $url, $matches ) ) {
			$storyId = $matches[1];
			// $title = $this->toolbox->getFormattedTitleFromId($storyId);
			return "EKBStory:$storyId";
		}
		return false;
	}

	/**
	 * @param string $content
	 * @param int $pageId
	 * @param int $version
	 * @return string
	 */
	public function findLinks( $content, $pageId, $version ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$ekbPages = $this->dataBuckets->getBucketData( 'ekb_stories' );
		$currentPage = $wikiPages[$pageId];
		$content = preg_replace_callback(
			'/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/i',
			function ( $matches ) use ( $wikiPages, $ekbPages, $pageId, $version, $currentPage ) {
				$linkTarget = str_replace( ' ', '_', urldecode( $matches[1] ) );
				$linkText = isset( $matches[2] ) ? trim( urldecode( $matches[2] ) ) : $linkTarget;
				$namespace = '';
				$title = $linkTarget;
				$fragment = '';
				if ( preg_match( '/^([^:]+):(.+)$/', $title, $nsParts ) ) {
					$namespace = $nsParts[1];
					$title = trim( $nsParts[2] );
				}
				if ( preg_match( '/^(.+?)(?:#(.+))?$/', $title, $fragParts ) ) {
					$title = trim( $fragParts[1] );
					$fragment = isset( $fragParts[2] ) ? $fragParts[2] : '';
				}
				if ( $namespace === 'Category' ) {
					return $matches[0];
				}
				if ( $namespace === 'EKBStory' && isset( $ekbPages[$title] ) ) {
					return "[[" . $ekbPages[$title]['formatted_title'] . "]]";
				}
				if ( strpos( $linkTarget, '#' ) === 0 ) {
					return urldecode( $matches[0] );
				}
				$targetExists = false;
				foreach ( $wikiPages as $page ) {
					if (
						$page['formatted_title'] === $linkTarget
						|| $page['formatted_title'] === $namespace . ':' . $title
						|| ( $namespace === 'File' && $page['formatted_title'] === $title )
					) {
						$targetExists = true;
						$linkTarget = $page['formatted_title'];
						break;
					}
				}
				if ( !$targetExists ) {
					foreach ( $wikiPages as $page ) {
						if (
							( $page['wiki_id'] === $currentPage['wiki_id']
								|| ( isset( $page['project_name'] )
									&& $page['project_name'] == $namespace )
								|| ( isset( $page['project_identifier'] )
									&& $page['project_identifier'] == $namespace )
								|| ( isset( $page['project_id'] )
									&& $page['project_id'] == $namespace )
							) && $this->toolbox->isSameTitle( $page['title'], $title )
						) {
							$targetExists = true;
							$linkTarget = $page['formatted_title'];
							break;
						}
					}
				}
				if ( !$targetExists ) {
					foreach ( $wikiPages as $page ) {
						if ( $this->toolbox->isSameTitle( $page['title'], $linkTarget )
							|| $this->toolbox->isSameTitle( $page['title'], $title )
						) {
							$targetExists = true;
							$linkTarget = $page['formatted_title'];
							break;
						}
					}
				}
				if ( !$targetExists ) {
					// Only output message for most recent version
					if ( $version == $currentPage['version'] ) {
						$fTitle = $currentPage['formatted_title'];
						print_r( "\n$fTitle ($pageId-$version) has invalid link $linkTarget" );
					}
					return $matches[0];
				}
				if ( $fragment !== '' ) {
					$linkTarget .= '#' . $fragment;
				}
				if ( strpos( $linkText, 'class=image' ) === 0 || strpos( $linkText, 'class=ckeditor' ) === 0 ) {
					$linkText = $linkTarget;
				}
				return ( $linkTarget === $linkText )
					? "[[" . $linkTarget . "]]"
					: "[[" . $linkTarget . "|" . $linkText . "]]";
			},
			$content
		);
		return $content;
	}
}
