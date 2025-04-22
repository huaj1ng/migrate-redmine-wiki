<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use Exception;
use HalloWelt\MediaWiki\Lib\Migration\IAnalyzer;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;

class Analyze extends SimpleCommand {
	/**
	 * @return void
	 */
	protected function configure() {
		$this->setName( 'analyze' )->setDescription(
			'Analyze pages, attachments and their revisions from database'
		);
		parent::configure();
	}

	/**
	 * @return array
	 */
	protected function getBucketKeys() {
		return [
			'wiki-pages',
			'page-revisions',
			'samename-attachments',
			'attachment-files',
			'diagram-contents',
		];
	}

	/**
	 * @return int
	 */
	protected function process(): int {
		$this->output->writeln( "Loading DB connection from '{$this->currentFile->getFilename()}'" );
		$analyzerFactoryCallbacks = $this->config['analyzers'];
		foreach ( $analyzerFactoryCallbacks as $key => $callback ) {
			$analyzer = call_user_func_array(
				$callback,
				[ $this->config, $this->workspace, $this->buckets ]
			);
			if ( $analyzer instanceof IAnalyzer === false ) {
				throw new Exception(
					"Factory callback for analyzer '$key' did not return an "
					. "IAnalyzer object"
				);
			}
			if ( $analyzer instanceof IOutputAwareInterface ) {
				$analyzer->setOutput( $this->output );
			}
			$result = $analyzer->analyze( $this->currentFile );
		}
		return 0;
	}
}
