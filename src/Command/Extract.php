<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use Exception;
use HalloWelt\MediaWiki\Lib\Migration\IExtractor;
use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;

class Extract extends SimpleCommand {
	/**
	 * @return void
	 */
	protected function configure() {
		$this->setName( 'extract' )->setDescription(
			'Extract all wanted attachments into the workspace'
		);
		parent::configure();
	}

	/**
	 * @return int
	 */
	protected function process(): int {
		if ( !is_dir( $this->src ) || !is_dir( $this->dest ) ) {
			throw new Exception( "Both source and destination path must be valid directories" );
		}
		$extractorFactoryCallbacks = $this->config['extractors'];
		foreach ( $extractorFactoryCallbacks as $key => $callback ) {
			$extractor = call_user_func_array(
				$callback,
				[ $this->config, $this->workspace, $this->buckets ]
			);
			if ( $extractor instanceof IExtractor === false ) {
				throw new Exception(
					"Factory callback for extractor '$key' did not return an "
					. "IExtractor object"
				);
			}
			if ( $extractor instanceof IOutputAwareInterface ) {
				$extractor->setOutput( $this->output );
			}

			$result = $extractor->extract( $this->currentFile );
			if ( $result === false ) {
				$this->output->writeln( "<error>Extractor '$key' failed.</error>" );
				return 1;
			}
		}
		return 0;
	}
}
