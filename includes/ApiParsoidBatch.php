<?php

use IPSet\IPSet;

class ApiParsoidBatch extends ApiBase {
	public function execute() {
		$params = $this->extractRequestParams();

		$context = $this->getContext();
		$config = $context->getConfig();
		$ipset = new IPSet( $config->get( 'ParsoidBatchAPI_AllowedIPs' ) );
		if ( !$ipset->match( $context->getRequest()->getIP() ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( 'apierror-parsoid-batch-notallowed', 'not_allowed' );
			} else {
				$this->dieUsage( "Client IP address not in ParsoidBatchAPI_AllowedIPs",
					'not_allowed' );
			}
		}

		// Parameter validation
		$batch = json_decode( $params['batch'], true );
		if ( !is_array( $batch ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( 'apierror-parsoid-batch-invalidbatch', 'invalid_batch' );
			} else {
				$this->dieUsage( "Invalid batch, must be array", 'invalid_batch' );
			}
		}
		if ( count( $batch ) > 500 ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( 'apierror-parsoid-batch-batchtoolarge', 'batch_too_large' );
			} else {
				$this->dieUsage( "Batch too large, limit is 500", 'batch_too_large' );
			}
		}
		wfIncrStats( 'ParsoidBatchAPI.batches' );
		wfIncrStats( 'ParsoidBatchAPI.items', count( $batch ) );

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
				$this->assertScalarOrMissing( $itemParams, 'page' );
				// Normalize the filename in $batch so that we can find the corresponding
				// file in the findFiles() result
				$title = Title::makeTitleSafe( NS_FILE, $itemParams['filename'] );
				if ( $title ) {
					$filenames[] = $batch[$itemIndex]['filename'] = $title->getDBkey();
				}
			} else {
				if ( is_callable( array( $this, 'dieWithError' ) ) ) {
					$this->dieWithError(
						[ 'apierror-parsoid-batch-invalidaction', wfEscapeWikiText( $itemIndex ) ], 'invalid_action'
					);
				} else {
					$this->dieUsage( "Invalid action in item index $itemIndex", 'invalid_action' );
				}
			}
		}
		if ( $size > 1024 * $config->get( 'MaxArticleSize' ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( 'apierror-parsoid-batch-texttoobig', 'text_too_big' );
			} else {
				$this->dieUsage( "Input text exceeds maximum article size", 'text_too_big' );
			}
		}

		// Now do the thing
		if ( count( $filenames ) ) {
			$files = RepoGroup::singleton()->findFiles( $filenames );
		} else {
			$files = array();
		}

		$batchResult = array();
		$result = $this->getResult();
		foreach ( $batch as $itemIndex => $itemParams ) {
			$action = $itemParams['action'];
			if ( $action === 'parse' || $action === 'preprocess' ) {
				$title = Title::newFromText( $itemParams['title'] );
				if ( !$title ) {
					if ( is_callable( array( $this, 'dieWithError' ) ) ) {
						$this->dieWithError(
							[ 'apierror-parsoid-batch-invalidtitle', wfEscapeWikiText( $itemIndex ) ], 'invalid_title'
						);
					} else {
						$this->dieUsage( "Invalid title ($itemIndex)", 'invalid_title' );
					}
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
				$page = isset( $itemParams['page'] ) ? Title::newFromText( $itemParams['page'] ) : null;
				$itemResult = $this->imageinfo( $filename, $file, $txopts, $page );
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

	protected function assertScalar( array $array, $key ) {
		if ( !isset( $array[$key] ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$eKey = wfEscapeWikiText( $key ); // Might be user-supplied via txopts
				$this->dieWithError( array( 'apierror-missingparam', $eKey ), "missing_$eKey" );
			} else {
				$this->dieUsage(
					"The $key parameter is required",
					"missing_$key" );
			}
		}
		if ( !is_scalar( $array[$key] ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$eKey = wfEscapeWikiText( $key ); // Might be user-supplied via txopts
				$this->dieWithError( array( 'apierror-parsoid-batch-mustbescalar', $eKey ), "invalid_$eKey" );
			} else {
				$this->dieUsage(
					"The $key parameter must be a scalar",
					"invalid_$key" );
			}
		}
	}

	protected function assertScalarOrMissing( array $array, $key ) {
		if ( isset( $array[$key] ) && !is_scalar( $array[$key] ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( array( 'apierror-parsoid-batch-mustbescalar', $key ), "invalid_$key" );
			} else {
				$this->dieUsage(
					"The $key parameter must be a scalar",
					"invalid_$key" );
			}
		}
	}

	protected function assertArray( array $array, $key ) {
		if ( !isset( $array[$key] ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( array( 'apierror-missingparam', $key ), "missing_$key" );
			} else {
				$this->dieUsage(
					"The $key parameter is required",
					"missing_$key" );
			}
		}
		if ( !is_array( $array[$key] ) ) {
			if ( is_callable( array( $this, 'dieWithError' ) ) ) {
				$this->dieWithError( array( 'apierror-parsoid-batch-mustbearray', $key ), "invalid_$key" );
			} else {
				$this->dieUsage(
					"The $key parameter must be an array",
					"invalid_$key" );
			}
		}
	}

	/**
	 * @param string $text
	 * @param Title $title
	 *
	 * @return array
	 * @throws MWException
	 * @throws MWUnknownContentModelException
	 */
	protected function parse( $text, Title $title ) {
		global $wgParser;

		$contentHandler = ContentHandler::getForModelID( CONTENT_MODEL_WIKITEXT );
		$options = $contentHandler->makeParserOptions( $this->getContext() );
		$options->enableLimitReport( false );
		$options->setWrapOutputClass( false ); // Parsoid doesn't want the output wrapper
		$out = $wgParser->parse( $text, $title, $options );
		return array(
			'text' => $out->getText(),
			'modules' => array_values( array_unique( $out->getModules() ) ),
			'modulescripts' => array_values( array_unique( $out->getModuleScripts() ) ),
			'modulestyles' => array_values( array_unique( $out->getModuleStyles() ) ),
			'categories' => $this->formatCategoryLinks( $out->getCategories() ),
		);
	}

	/**
	 * @param string $text
	 * @param Title $title
	 * @param int|bool $revid
	 *
	 * @return array
	 * @throws MWException
	 * @throws MWUnknownContentModelException
	 */
	protected function preprocess( $text, Title $title, $revid ) {
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

	protected function formatCategoryLinks( array $links ) {
		$result = array();
		foreach ( $links as $link => $sortkey ) {
			$result[] = array(
				'*' => $link,
				'sortkey' => $sortkey
			);
		}
		return $result;
	}

	protected function formatProperties( array $props ) {
		$result = array();
		foreach ( $props as $name => $value ) {
			$result[] = array(
				'*' => $value,
				'name' => $name
			);
		}
		return $result;
	}

	/**
	 * @param string $filename
	 * @param File|null $file
	 * @param array $txopts
	 * @param Title|null $page Title for wfIsBadImage() context
	 *
	 * @return array|null
	 */
	protected function imageinfo( $filename, $file, array $txopts, $page ) {
		if ( !$file ) {
			// Short return code for missing images
			return null;
		}
		$result = array(
			'width' => $file->getWidth(),
			'height' => $file->getHeight(),
			'size' => $file->getSize(),
			'mediatype' => $file->getMediaType(),
			'mime' => $file->getMimeType(),
			'url' => wfExpandUrl( $file->getFullUrl(), PROTO_CURRENT ),
			'mustRender' => $file->mustRender(),
			'badFile' => wfIsBadImage( $filename, $page ?: false ),
		);
		$length = $file->getLength();
		if ( $length ) {
			$result['duration'] = (float)$length;
		}
		$txopts = $this->makeTransformOptions( $file, $txopts );
		$mto = $file->transform( $txopts );
		if ( $mto ) {
			if ( $mto->isError() ) {
				$result['thumberror'] = $mto->toText();
			} else {
				if ( $txopts ) {
					// Do srcset scaling
					Linker::processResponsiveImages( $file, $mto, $txopts );
					if ( count( $mto->responsiveUrls ) ) {
						$result['responsiveUrls'] = array();
						foreach ( $mto->responsiveUrls as $density => $url ) {
							$result['responsiveUrls'][$density] = wfExpandUrl(
								$url, PROTO_CURRENT );
						}
					}
				}

				// Proposed MediaTransformOutput serialization method for T51896 etc.
				if ( is_callable( array( $mto, 'getAPIData' ) ) ) {
					$result['thumbdata'] = $mto->getAPIData();
				}

				$result['thumburl'] = wfExpandUrl( $mto->getUrl(), PROTO_CURRENT );
				$result['thumbwidth'] = $mto->getWidth();
				$result['thumbheight'] = $mto->getHeight();
			}
		} else {
			$result['thumberror'] = "Presumably, invalid parameters, despite validation.";
		}
		return $result;
	}

	/**
	 * @param File $file
	 * @param array $hp
	 *
	 * @return array
	 */
	protected function makeTransformOptions( $file, array $hp ) {
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
