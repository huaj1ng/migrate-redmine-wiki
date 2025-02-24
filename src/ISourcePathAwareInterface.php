<?php

namespace HalloWelt\MigrateRedmineWiki;

interface ISourcePathAwareInterface {

	/**
	 *
	 * @param string $path
	 * @return void
	 */
	public function setSourcePath( $path );
}
