<?php

class ApiParsoidBatch extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();

		$context = $this->getContext();
		$config = $context->getConfig();
		$ipset = new IPSet( $config->get( 'ParsoidBatchAPI_AllowedIPs' ) );
		if ( !$ipset->match( $context->getRequest()->getIP() ) ) {
			$this->dieUsage( "Client IP address not in ParsoidBatchAPI_AllowedIPs",
				'not_allowed' );
		}

		// Parameter validation
		$batch = json_decode( $params['batch'], true );
		if ( !is_array( $batch ) ) {
			$this->dieUsage( "Invalid batch, must be array", 'invalid_batch' );
		}
		if ( count( $batch ) > 500 ) {
			$this->dieUsage( "Batch too large, limit is 500", 'batch_too_large' );
		}
		$size = 0;
		$filenames = array();
		foreach ( $batch as $itemIndex => $itemParams ) {
			$action = $itemParams['action'];
			$this->assertScalar( $itemParams, 'action' );
			if ( $action === 'parse' || $action === 'preprocess' ) {
				$this->assertScalar( $itemParams, 'title' );
				$this->assertScalar( $itemParams, 'text' );
				$this->assertScalarOrMissing( $itemParams, 'revid' );
				$size += strlen( $itemParams['text'] );
			} elseif ( $action === 'imageinfo' ) {
				$this->assertScalar( $itemParams, 'filename' );
				if ( isset( $itemParams['txopts'] ) ) {
					$this->assertArray( $itemParams, 'txopts' );
					$txopts = $itemParams['txopts'];
					foreach ( $txopts as $k => $v ) {
						$this->assertScalar( $txopts, $k );
					}
				}
				$filenames[] = $itemParams['filename'];
			} else {
				$this->dieUsage( "Invalid action in item index $itemIndex", 'invalid_action' );
			}
		}
		if ( $size > 1024 * $config->get( 'MaxArticleSize' ) ) {
			$this->dieUsage( "Input text exceeds maximum article size", 'text_too_big' );
		}

		// Now do the thing
		if ( count( $filenames ) ) {
			$files = RepoGroup::singleton()->findFiles( $filenames );
		}

		$batchResult = array();
		$result = $this->getResult();
		foreach ( $batch as $itemIndex => $itemParams ) {
			$action = $itemParams['action'];
			if ( $action === 'parse' || $action === 'preprocess' ) {
				$title = Title::newFromText( $itemParams['title'] );
				if ( !$title ) {
					$this->dieUsage( "Invalid title ($itemIndex)", 'invalid_title' );
				}
				$text = $itemParams['text'];
				$revid = isset( $itemParams['revid'] ) ? intval( $itemParams['revid'] ) : false;
				switch ( $action ) {
					case 'parse':
						$itemResult = $this->parse( $text, $title );
						break;
					case 'preprocess':
						$itemResult = $this->preprocess( $text, $title, $revid );
						break;
				}
			} elseif ( $action === 'imageinfo' ) {
				$filename = $itemParams['filename'];
				$file = isset( $files[$filename] ) ? $files[$filename] : null;
				$txopts = isset( $itemParams['txopts'] ) ? $itemParams['txopts'] : array();
				$itemResult = $this->imageinfo( $filename, $file, $txopts );
			} else {
				throw new Exception( "Invalid action despite validation already being done" );
			}
			$batchResult[] = $itemResult;
		}
		$result->addValue( null, 'parsoid-batch', $batchResult,
			// No need to merge
			ApiResult::OVERRIDE |
			// Don't iterate over the whole array and mangle random bits of it
			ApiResult::NO_VALIDATE );
	}

	protected function assertScalar( $array, $key ) {
		if ( !isset( $array[$key] ) ) {
			$this->dieUsage(
				"The $key parameter is required",
				"missing_$key" );
		}
		if ( !is_scalar( $array[$key] ) ) {
			$this->dieUsage(
				"The $key parameter must be a scalar",
				"invalid_$key" );
		}
	}

	protected function assertScalarOrMissing( $array, $key ) {
		if ( isset( $array[$key] ) && !is_scalar( $array[$key] ) ) {
			$this->dieUsage(
				"The $key parameter must be a scalar",
				"invalid_$key" );
		}
	}

	protected function assertArray( $array, $key ) {
		if ( !isset( $array[$key] ) ) {
			$this->dieUsage(
				"The $key parameter is required",
				"missing_$key" );
		}
		if ( !is_array( $array[$key] ) ) {
			$this->dieUsage(
				"The $key parameter must be an array",
				"invalid_$key" );
		}
	}

	protected function parse( $text, $title ) {
		global $wgParser;

		$contentHandler = ContentHandler::getForModelID( CONTENT_MODEL_WIKITEXT );
		$options = $contentHandler->makeParserOptions( $this->getContext() );
		$options->enableLimitReport( false );
		$out = $wgParser->parse( $text, $title, $options );
		return array(
			'text' => $out->getText(),
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulescripts' => array_values( array_unique( $out->getModuleScripts() ) ),
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'categories' => $this->formatCategoryLinks( $out->getCategories() ),
		);
	}

	protected function preprocess( $text, $title, $revid ) {
		global $wgParser;

		$contentHandler = ContentHandler::getForModelID( CONTENT_MODEL_WIKITEXT );
		$options = $contentHandler->makeParserOptions( $this->getContext() );
		$wikitext = $wgParser->preprocess( $text, $title, $options, $revid );
		$out = $wgParser->getOutput();
		return array(
			'wikitext' => $wikitext,
			'categories' => $this->formatCategoryLinks( $out->getCategories() ),
			'properties' => $this->formatProperties( $out->getProperties() ),
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulescripts' => array_values( array_unique( $out->getModuleScripts() ) ),
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
		);
	}

	protected function formatCategoryLinks( $links ) {
		$result = array();
		foreach ( $links as $link => $sortkey ) {
			$result[] = array(
				'*' => $link,
				'sortkey' => $sortkey
			);
		}
		return $result;
	}

	protected function formatProperties( $props ) {
		$result = array();
		foreach ( $props as $name => $value ) {
			$result[] = array(
				'*' => $value,
				'name' => $name
			);
		}
		return $result;
	}

	protected function imageinfo( $filename, $file, $txopts ) {
		if ( !$file ) {
			// Short return code for missing images
			return null;
		}
		$result = array(
			'width' => $file->getWidth(),
			'height' => $file->getHeight(),
			'mediatype' => $file->getMediaType(),
			'url' => wfExpandUrl( $file->getFullURL(), PROTO_CURRENT ),
			'mustRender' => $file->mustRender()
		);

		$txopts = $this->makeTransformOptions( $file, $txopts );
		$mto = $file->transform( $txopts );
		if ( $mto ) {
			if ( $mto->isError() ) {
				$result['thumberror'] = $mto->toText();
			} else {
				// Proposed MediaTransformOutput serialization method for T51896 etc.
				if ( is_callable( $mto, 'getAPIData' ) ) {
					$result['thumbdata'] = $mto->getAPIData();
				}

				$result['thumburl'] = wfExpandUrl( $mto->getUrl(), PROTO_CURRENT );
				$result['thumbwidth'] = $mto->getWidth();
				$result['thumbheight'] = $mto->getHeight();
			}
		}
		return $result;
	}

	protected function makeTransformOptions( $file, $hp ) {
		// Validate the input parameters like Parser::makeImage()
		$handler = $file->getHandler();
		if ( !$handler ) {
			return array(); // will get iconThumb()
		}
		foreach ( $hp as $name => $value ) {
			if ( !$handler->validateParam( $name, $value ) ) {
				unset( $hp[$name] );
			}
		}

		// This part is similar to Linker::makeImageLink(). If there is no width,
		// set one based on the source file size.
		$page = isset( $hp['page'] ) ? $hp['page'] : false;
		if ( !isset( $hp['width'] ) ) {
			if ( isset( $hp['height'] ) && $file->isVectorized() ) {
				// If it's a vector image, and user only specifies height
				// we don't want it to be limited by its "normal" width.
				global $wgSVGMaxSize;
				$hp['width'] = $wgSVGMaxSize;
			} else {
				$hp['width'] = $file->getWidth( $page );
			}

			// We don't need to fill in a default thumbnail width here, since
			// that is done by Parsoid. Parsoid always sets the width parameter
			// for thumbnails.
		}

		return $hp;
	}

	public function isInternal() {
		return true;
	}

	public function getAllowedParams() {
		return array(
			'batch' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			)
		);
	}
}
