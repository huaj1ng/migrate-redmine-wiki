<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;

class Convert extends SimpleCommand {
	/**
	 * @return void
	 */
	protected function configure() {
		$this->setName( 'convert' )->setDescription(
			'Convert page contents to wikitext'
		);
		parent::configure();
	}

	/**
	 * @return array
	 */
	protected function getBucketKeys() {
		return [
			'revision-wikitext',
		];
	}

	/**
	 * @return int
	 */
	protected function process(): int {
		$converterFactoryCallbacks = $this->config['converters'];
		foreach ( $converterFactoryCallbacks as $key => $callback ) {
			$converter = call_user_func_array(
				$callback,
				[ $this->config, $this->workspace, $this->buckets ]
			);
			// cannot check for instanceof IConverter here, skipped
			if ( $converter instanceof IOutputAwareInterface ) {
				$converter->setOutput( $this->output );
			}
			$result = $converter->convert();
			if ( $result === false ) {
				$this->output->writeln( "<error>Converter '$key' failed.</error>" );
				return 1;
			}
		}
		return 0;
	}
}
