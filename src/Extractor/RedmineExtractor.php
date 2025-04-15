<?php

namespace HalloWelt\MigrateRedmineWiki\Extractor;

use HalloWelt\MediaWiki\Lib\Migration\IExtractor;
use HalloWelt\MigrateRedmineWiki\SimpleHandler;
use SplFileInfo;

class RedmineExtractor extends SimpleHandler implements IExtractor {

	/** @var array */
	protected $dataBucketList = [
		'attachment-files',
	];

	/**
	 * @param SplFileInfo $sourceDir
	 * @return bool
	 */
	public function extract( SplFileInfo $sourceDir ): bool {
		$attachments = $this->dataBuckets->getBucketData( 'attachment-files' );
		foreach ( $attachments as $attachment ) {
			foreach ( $attachment as $file ) {
				$sourcePath = $sourceDir->getPathname() . '/' . $file['source_path'];
				if ( !is_file( $sourcePath ) ) {
					print_r( "File not found: " . $sourcePath . "\n" );
					continue;
				}
				$targetPath = $this->workspace->saveUploadFile(
					$file['target_filename'],
					file_get_contents( $sourcePath )
				);
			}
		}
		return true;
	}
}
