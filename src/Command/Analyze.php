<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use HalloWelt\MediaWiki\Lib\Migration\CliCommandBase;
use HalloWelt\MediaWiki\Lib\Migration\DataBuckets;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;
use HalloWelt\MigrateRedmineWiki\Analyzer\RedmineWikiAnalyzer;
use HalloWelt\MigrateRedmineWiki\ISourcePathAwareInterface;

class Analyze extends CliCommandBase {
	protected function configure() {
		$this->setName( 'analyze' )->setDescription( 'Test Redmine wiki migration scripts' );
		$config = parent::configure();
	}

	/**
	 *
	 * @inheritDoc
	 */
	protected function getBucketKeys() {
		return [];
	}

	protected function beforeProcessFiles() {
		parent::beforeProcessFiles();
		// Explicitly reset the persisted data
		$this->buckets = new DataBuckets( $this->getBucketKeys() );
	}

	protected function doProcessFile(): bool {
		$analyzer = new RedmineWikiAnalyzer( $this->config, $this->workspace, $this->buckets );
		if ( $analyzer instanceof IAnalyzer === false ) {
			throw new \Exception(
				"Factory callback for analyzer '$key' did not return an "
				. "IAnalyzer object"
			);
		}
		if ( $analyzer instanceof IOutputAwareInterface ) {
			$analyzer->setOutput( $this->output );
		}
		if ( $analyzer instanceof ISourcePathAwareInterface ) {
			$analyzer->setSourcePath( $this->src );
		}
		$result = $analyzer->analyze( $this->currentFile );

		return true;
	}

	/**
	 * @param array $config
	 * @return Analyze
	 */
	public static function factory( $config ): Analyze {
		return new static( $config );
	}
}
