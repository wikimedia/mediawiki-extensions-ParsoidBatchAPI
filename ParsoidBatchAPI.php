<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'ParsoidBatchAPI' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['ParsoidBatchAPI'] = __DIR__ . '/i18n';
	return true;
} else {
	die( 'This version of the ParsoidBatchAPI extension requires MediaWiki 1.25+' );
}
