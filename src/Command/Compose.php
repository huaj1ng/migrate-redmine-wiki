<?php

namespace HalloWelt\MigrateRedmineWiki\Command;

use HalloWelt\MediaWiki\Lib\Migration\IOutputAwareInterface;

class Compose extends SimpleCommand {
	/**
	 * @return void
	 */
	protected function configure() {
		$this->setName( 'compose' )->setDescription(
			'Compose xml files to import into MediaWiki'
		);
		parent::configure();
	}

	/**
	 * @return int
	 */
	protected function process(): int {
		$composerFactoryCallbacks = $this->config['composers'];
		foreach ( $composerFactoryCallbacks as $key => $callback ) {
			$composer = call_user_func_array(
				$callback,
				[ $this->config, $this->workspace, $this->buckets ]
			);
			if ( $composer instanceof IOutputAwareInterface ) {
				$composer->setOutput( $this->output );
			}
			$result = $composer->buildAndSave(
				$this->dest . "/result/$key-output.xml"
			);
			if ( $result === false ) {
				$this->output->writeln( "<error>Composer '$key' failed.</error>" );
				return 1;
			}
		}
		return 0;
	}
}
