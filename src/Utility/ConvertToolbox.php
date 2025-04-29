<?php

namespace HalloWelt\MigrateRedmineWiki\Utility;

use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\Workspace;

class ConvertToolbox {

	/** @var Workspace */
	protected $workspace = null;

	/** @var DataBuckets */
	protected $dataBuckets = null;

	/** @var array */
	protected $customizations = [];

	/**
	 * This array maps lexer names used in (Easy)Redmine that are not directly
	 * supported by MediaWiki extension SyntaxHighlight.
	 */
	public const MAPPABLE_LANGUAGE = [
		'as' => 'actionscript',
		'as3' => 'actionscript3',
		'aug' => 'augeas',
		'batchfile' => 'bat',
		'terminal' => 'console',
		'shell_session' => 'shell-session',
		'dlang' => 'd',
		'patch' => 'diff',
		'containerfile' => 'dockerfile',
		'Containerfile' => 'Dockerfile',
		'e-mail' => 'email',
		'eruby' => 'erb',
		'ff' => 'freefem',
		'behat' => 'gherkin',
		'nextflow' => 'groovy',
		'nf' => 'groovy',
		'HAML' => 'haml',
		'hbs' => 'handlebars',
		'mustache' => 'handlebars',
		'pry' => 'irb',
		'isa' => 'isabelle',
		'Isabelle' => 'isabelle',
		'literate_haskell' => 'literate-haskell',
		'lithaskell' => 'literate-haskell',
		'ls' => 'livescript',
		'gnumake' => 'make',
		'mkd' => 'markdown',
		'wl' => 'mathematica',
		'wolfram' => 'mathematica',
		'm' => 'matlab',
		'objective_c' => 'objective-c',
		'obj_c' => 'obj-c',
		'objective_cpp' => 'objective-c++',
		'objcpp' => 'objc++',
		'obj-cpp' => 'objc++',
		'obj_cpp' => 'objc++',
		'objectivecpp' => 'objective-c++',
		'obj-c++' => 'objc++',
		'obj_c++' => 'objc++',
		'objectivec++' => 'objective-c++',
		'plaintext' => 'text',
		'plist' => 'text',
		'ps' => 'postscript',
		'eps' => 'postscript',
		'microsoftshell' => 'powershell',
		'msshell' => 'powershell',
		'pp' => 'puppet',
		'robot_framework' => 'robotframework',
		'robot' => 'robotframework',
		'robot-framework' => 'robotframework',
		'ml' => 'sml',
		'TeX' => 'tex',
		'LaTeX' => ' latex',
		'visualbasic' => 'vb',
		'varnishconf' => 'vcl',
		'varnish' => 'vcl',
		'viml' => 'vim',
		'vimscript' => 'vim',
		'zir' => 'zig',
	];

	public const UNSUPPORTED_LANGUAGE = [
		'apex',
		'apiblueprint',
		'apib',
		'armasm',
		'biml',
		'bpf',
		'brightscript',
		'bs',
		'brs',
		'bsl',
		'cfscript',
		'cisco_ios',
		'cmhg',
		'codeowners',
		'conf',
		'config',
		'configuration',
		'csvs',
		'dafny',
		'datastudio',
		'digdag',
		'elm',
		'eex',
		'leex',
		'heex',
		'epp',
		'escape',
		'esc',
		'fluent',
		'ftl',
		'ghc-cmm',
		'cmm',
		'ghc-core',
		'gradle',
		'graphql',
		'hack',
		'hh',
		'hcl',
		'hocon',
		'hql',
		'idlang',
		'iecst',
		'isbl',
		'janet',
		'jdn',
		'jsl',
		'json-doc',
		'jsonc',
		'json5',
		'jsonnet',
		'jsx',
		'react',
		'literate_coffeescript',
		'litcoffee',
		'lustre',
		'lutin',
		'm68k',
		'magik',
		'minizinc',
		'mojo',
		'msgtrans',
		'nesasm',
		'nes',
		'nial',
		'ocl',
		'OCL',
		'opentype_feature_file',
		'fea',
		'opentype',
		'opentypefeature',
		'p4',
		'plsql',
		'prometheus',
		'q',
		'kdb+',
		'rego',
		'rescript',
		'rml',
		'slice',
		'sqf',
		'ssh',
		'svelte',
		'systemd',
		'unit-file',
		'syzlang',
		'syzprog',
		'tsx',
		'ttcn3',
		'tulip',
		'tulip',
		'vue',
		'vuejs',
		'wollok',
		'xojo',
		'realbasic',
		'xpath',
	];

	public const ATTACHMENT_EXTENSION = [
		'png',
		'jpg',
		'jpeg',
		'gif',
		'mp4',
		'ico',
		'pic',
		'bmp',
		'tiff',
		'tif',
		'svg',
		'webp',
	];

	/**
	 * @param Workspace $workspace
	 */
	public function __construct( Workspace $workspace ) {
		$this->workspace = $workspace;
		$this->dataBuckets = new DataBuckets( [
			'wiki-pages',
			'customizations',
			'missing-titles',
			'missing-attachments',
		] );
		$this->dataBuckets->loadFromWorkspace( $this->workspace );
		$customizations = $this->dataBuckets->getBucketData( 'customizations' );
		if ( !isset( $customizations['is-enabled'] ) || $customizations['is-enabled'] !== true ) {
			print_r( "\nNo customization enabled\n" );
			$this->customizations['is-enabled'] = false;
		} else {
			$this->customizations = $customizations;
			print_r( "\nCustomizations loaded\n" );
		}
	}

	public function __destruct() {
		$this->dataBuckets->saveToWorkspace( $this->workspace );
	}

	/**
	 * @return array
	 */
	public function getCustomizations() {
		return $this->customizations;
	}

	/**
	 * @return string|false
	 */
	public function getDomain() {
		$customizations = $this->getCustomizations();
		if ( !isset( $customizations['redmine-domain'] ) ) {
			return false;
		}
		$domain = $customizations['redmine-domain'];
		return $domain;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function replaceCustomized( $content ) {
		if ( isset( $this->customizations['customized-replace'] ) ) {
			foreach ( $this->customizations['customized-replace'] as $old => $new ) {
				$content = str_replace( $old, $new, $content );
			}
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function replaceInlineBeforePandoc( $content ) {
		$lines = explode( "\n", $content );
		foreach ( $lines as $index => $line ) {
			// replace `":</` that pandoc misinterprets
			$line = preg_replace( '/\":<\//', '":' . "\n" . '</', $line );
		}
		return implode( "\n", $lines );
	}

	/**
	 * A workaround resolving problematic usage of first headings
	 *
	 * @param string $content
	 * @return string
	 */
	public function replaceFirstLinesAfterPandoc( $content ) {
		$lines = explode( "\n", $content, 3 );
		if ( preg_match( '/^=+\s+.+\s+=+$/', $lines[0] ) ) {
			$lines[0] = '<!--' . $lines[0] . '-->';
			$content = implode( "\n", $lines );
		} elseif ( !empty( $lines[1] ) && preg_match( '/^=+\s+.+\s+=+$/', $lines[1] ) ) {
			$lines[1] = '<!--' . $lines[1] . '-->';
			$content = implode( "\n", $lines );
		}
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function replaceEncodedEntities( $content ) {
		$encodedEntities = [
			'&lt;' => '<',
			'&gt;' => '>',
			'&amp;' => '&',
			'&quot;' => '"',
			'&amp;lt;' => '<',
			'&amp;gt;' => '>',
			'&amp;amp;' => '&',
			'&amp;quot;' => '"',
		];
		$content = str_replace( array_keys( $encodedEntities ), array_values( $encodedEntities ), $content );
		$content = preg_replace( '/&amp;([a-z0-9]+);/i', '&$1;', $content );
		$content = preg_replace( '/&amp;#([0-9]+);/i', '&#$1;', $content );
		$content = preg_replace( '/&amp;#x([0-9a-f]+);/i', '&#x$1;', $content );
		$content = preg_replace( '/&amp;([a-z0-9]+)(?=[^a-z0-9])/i', '&$1', $content );
		return $content;
	}

	/**
	 * Converts HTML-encoded code blocks from (Easy)Redmine Textile syntax
	 * to MediaWiki Wikitext syntax with code highlighting provided by the
	 * SyntaxHighlight extension.
	 *
	 * @param string $content Content inside <pre> tags
	 * @return string
	 */
	public function convertCodeBlocks( $content ) {
		$content = $this->replaceEncodedEntities( $content );
		$codeSpanPattern = '/<span\s+class="[a-z0-9]+">(.*?)<\/span>/i';
		$content = preg_replace_callback( $codeSpanPattern, static function ( $matches ) {
			return $matches[1];
		}, $content );
		if ( preg_match( '/<code\s+class="([^"]+)">/', $content, $matches ) ) {
			$language = $this->convertLexerName( $matches[1] );
			$content = preg_replace( '/<code\s+class="([^"]+)">/', '', $content );
			$content = preg_replace( '/<\/code>$/', '', $content );
			// need further correspondence of language tags
			$content = "<syntaxhighlight lang=\"$language\">\n" . $content . "\n</syntaxhighlight>";
		} elseif ( preg_match( '/<code>/', $content ) ) {
			$content = preg_replace( '/<code>/', '', $content );
			$content = preg_replace( '/<\/code>/', '', $content );
			$content = "<pre>" . $content . "</pre>";
		} else {
			$content = "<pre>" . $content . "</pre>";
		}
		$content = $this->replaceEncodedEntities( $content );
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
		return $content;
	}

	/**
	 * This method tries to correspond language names to a supported lexer name,
	 * so that the tag <syntaxhighlight lang=""> can render syntax highlighting correctly.
	 * Unsupported languages will fall back to <pre> tags as preformatted text.
	 *
	 * Reference links:
	 *
	 * https://www.redmine.org/projects/redmine/wiki/RedmineCodeHighlightingLanguages
	 * https://pygments.org/docs/lexers/#pygments.lexers.business.ABAPLexer
	 *
	 * @param string $language
	 * @return string
	 */
	public function convertLexerName( $language ) {
		$language = preg_replace( '/\s/', '', $language );
		$language = preg_replace( '/language-/', '', $language );
		$language = preg_replace( '/syntaxhl/', '', $language );
		if ( array_key_exists( $language, self::MAPPABLE_LANGUAGE ) ) {
			return self::MAPPABLE_LANGUAGE[$language];
		}
		if ( in_array( $language, self::UNSUPPORTED_LANGUAGE, true ) ) {
			return 'text';
		}
		return $language;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function replaceCollapsedBlocks( $content ) {
		// Pre-handle headings inside collapse blocks
		$pattern = '/===\s*{{collapse\((.*?)\)\s*===/';
		$replacement = '{{collapse(=== $1 ===)';
		$content = preg_replace( $pattern, $replacement, $content );
		// Handle collapse blocks with title
		$pattern = '/{{collapse\((.*?)\)([\s\S]*?)(?<!\})\}\}(?!\})/s';
		$replacement = '<div class="toccolours mw-collapsible mw-collapsed">'
			. "\n$1\n"
			. '<div class="mw-collapsible-content">'
			. "\n$2\n"
			. '</div></div>';
		$content = preg_replace( $pattern, $replacement, $content );
		// Handle collapse blocks without title
		$pattern = '/{{collapse([\s\S]*?)(?<!\})\}\}(?!\})/s';
		$replacement = '<div class="toccolours mw-collapsible mw-collapsed">'
			. '<div class="mw-collapsible-content">'
			. "\n$1\n"
			. '</div></div>';
		$content = preg_replace( $pattern, $replacement, $content );
		return $content;
	}

	/**
	 * @param string $oldStart
	 * @param string $oldEnd
	 * @param string $newStart
	 * @param string $newEnd
	 * @param string $content
	 * @return string
	 */
	public function replaceInlineTitles( $oldStart, $oldEnd, $newStart, $newEnd, $content ) {
		$lines = explode( "\n", $content );
		foreach ( $lines as $index => $line ) {
			$parts = explode( $oldStart, $line );
			if ( count( $parts ) > 1 ) {
				$newLine = $parts[0];
				for ( $i = 1; $i < count( $parts ); $i++ ) {
					$part = $parts[$i];
					$pos = strpos( $part, $oldEnd );
					if ( $pos !== false ) {
						$title = substr( $part, 0, $pos );
						$remainder = substr( $part, $pos + strlen( $oldEnd ) );
						$newLine .= $newStart . $this->getFormattedTitle( $title ) . $newEnd . $remainder;
					} else {
						$newLine .= $oldStart . $part;
					}
				}
				$lines[$index] = $newLine;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function replaceInlineElements( $content ) {
		$lines = explode( "\n", $content );
		foreach ( $lines as $index => $line ) {
			// handle redundant <span> tags
			if ( preg_match( '/<span\s+id="[^"]*"><\/span>/', $line, $matches ) ) {
				unset( $lines[$index] );
			}
			// handle toc
			if ( preg_match( '/{{\s*(?:toc|>toc)\s*}}/', $line ) ) {
				$lines[$index] = str_replace( '{{toc}}', '__TOC__', $line );
				$lines[$index] = str_replace( '{{>toc}}', '__TOC__', $lines[$index] );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param string $title
	 * @return string
	 */
	public function getFormattedTitle( $title ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$foundKey = array_key_first( array_filter( $wikiPages, static function ( $page ) use ( $title ) {
			return isset( $page['title'] ) && $page['title'] === $title;
		} ) );
		if ( $foundKey !== null ) {
			return $wikiPages[$foundKey]['formatted_title'];
		}
		if ( substr( $title, -4 ) === '.png' || substr( $title, -4 ) === '.jpg' || substr( $title, -4 ) === '.gif' ) {
			$baseName = substr( $title, 0, -4 );
			$foundKey = array_key_first( array_filter( $wikiPages, static function ( $page ) use ( $baseName ) {
				return isset( $page['title'] ) && strpos( $page['title'], $baseName ) === 0;
			} ) );
			if ( $foundKey !== null ) {
				return $wikiPages[$foundKey]['formatted_title'];
			}
		}
		if ( isset( $this->customizations['title-cheatsheet'][$title] ) ) {
			return $this->customizations['title-cheatsheet'][$title];
		}
		$this->dataBuckets->addData( 'missing-titles', $title, true, false );
		return $title;
	}

	/**
	 * @param int $id
	 * @return string|null
	 */
	public function getFormattedTitleFromId( $id ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		if ( isset( $wikiPages[$id] ) ) {
			return $wikiPages[$id]['formatted_title'];
		}
		return null;
	}

	/**
	 * @param string $link
	 * @return string|false
	 */
	public function getAttachmentTitleFromLink( $link ) {
		$domain = $this->getDomain();
		if ( !$domain ) {
			return false;
		}
		$pattern = '/https?:\/\/' . preg_quote( $domain, '/' ) . '\/attachments\/';
		$pattern .= '(?:(?:download|thumbnail)\/)?';
		$pattern .= '(\d+)(?:\/[^?#\s]*)?(?:\?[^#\s]*)?(?:#[^\s]*)?/';
		if ( preg_match( $pattern, $link, $matches ) ) {
			$id = (int)$matches[1];
			$title = $this->getFormattedTitleFromId( $id + 1000000000 ) ?? "Attachment-$id";
			$this->dataBuckets->addData( 'missing-attachments', $link, true, false );
			return $title;
		}
		return false;
	}

	/**
	 * @param string $pageTitle
	 * @param string $targetTitle
	 * @return bool
	 */
	public function isSameTitle( $pageTitle, $targetTitle ) {
		if ( $pageTitle === $targetTitle ) {
			return true;
		}
		$pageTitle = str_replace( ' ', '_', $pageTitle );
		$pageTitle = str_replace( '.', '', $pageTitle );
		$targetTitle = str_replace( '.', '', $targetTitle );
		$targetTitle = str_replace( ',', '', $targetTitle );
		$targetTitle = str_replace( '/', '', $targetTitle );
		$targetTitle = str_replace( '?', '', $targetTitle );
		$targetTitle = str_replace( '¶', '', $targetTitle );
		$targetTitle = str_replace( '’', '\'', $targetTitle );
		$targetTitle = str_replace( '‘', '\'', $targetTitle );
		if ( strtolower( $pageTitle ) == strtolower( $targetTitle ) ) {
			return true;
		}
		return false;
	}
}
