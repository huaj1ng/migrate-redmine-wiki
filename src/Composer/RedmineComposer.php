<?php

namespace HalloWelt\MigrateRedmineWiki\Composer;

use DOMDocument;
use DOMElement;
use HalloWelt\MigrateRedmineWiki\SimpleHandler;

class RedmineComposer extends SimpleHandler {

	/** @var array */
	protected $dataBucketList = [
		'wiki-pages',
		'page-revisions',
		'revision-wikitext',
	];

	/** @var DOMDocument */
	protected $dom = null;

	/**
	 * @param string $destFilepath
	 * @return bool
	 */
	public function buildAndSave( $destFilepath ) {
		$wikiPages = $this->dataBuckets->getBucketData( 'wiki-pages' );
		$pageRevisions = $this->dataBuckets->getBucketData( 'page-revisions' );
		$revisionWikitext = $this->dataBuckets->getBucketData( 'revision-wikitext' );
		$this->dom = new DOMDocument();
		$this->dom->formatOutput = true;
		$this->dom->loadXML( '<mediawiki></mediawiki>' );
		foreach ( $wikiPages as $id => $page ) {
			$pageEl = $this->dom->createElement( 'page' );
			$this->addTextElTo( $pageEl, 'title', $page['formatted_title'] );
			$this->addTextElTo( $pageEl, 'id', $id );
			# addTextElTo( $pageEl, 'redirect', $page['redirect'] );
			foreach ( $pageRevisions[$id] as $version => $revision ) {
				$revEl = $this->dom->createElement( 'revision' );
				$this->addTextElTo( $revEl, 'id', $revision['rev_id'] );
				$this->addTextElTo( $revEl, 'parentid', $revision['parent_rev_id'] );
				$this->addTextElTo( $revEl, 'timestamp', $revision['updated_on'] );
				$this->addTextElTo( $revEl, 'comment', $revision['comments'] );
				$this->addTextElTo( $revEl, 'model', 'wikitext' );
				$this->addTextElTo( $revEl, 'format', 'text/x-wiki' );
				$contributorEl = $this->dom->createElement( 'contributor' );
				$username = ucfirst( strtolower( $revision['author_name'] ) );
				$this->addTextElTo( $contributorEl, 'username', $username );
				$this->addTextElTo( $contributorEl, 'id', $revision['author_id'] );
				$revEl->appendChild( $contributorEl );
				$this->addTextElTo( $revEl, 'text', $revisionWikitext[$id][$version] );
				$pageEl->appendChild( $revEl );
			}
			$this->dom->documentElement->appendChild( $pageEl );
		}
		$writtenBytes = file_put_contents(
			$destFilepath,
			$this->dom->saveXML( $this->dom->documentElement )
		);
		return $writtenBytes !== false;
	}

	/**
	 * @param DOMElement $targetEl
	 * @param string $name
	 * @param mixed $text
	 */
	public function addTextElTo( $targetEl, $name, $text ) {
		if ( $text === null ) {
			return;
		}
		$el = $this->dom->createElement( $name );
		$el->appendChild( $this->dom->createTextNode( $text ) );
		$targetEl->appendChild( $el );
	}
}
