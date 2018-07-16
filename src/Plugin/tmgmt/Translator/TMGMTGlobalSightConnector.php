<?php

namespace Drupal\tmgmt_globalsight\Plugin\tmgmt\Translator;

use Drupal\tmgmt\TranslatorInterface;
use nusoap_client;
use Drupal\Component\Utility\Html;

/**
 * GlobalSight connector.
 */
class TMGMTGlobalSightConnector {
	public $base_url = '';
	private $username = '';
	private $password = '';
	private $endpoint = '';
	private $proxyhost = FALSE; // ?
	private $proxyport = FALSE; // ?
	private $file_profile_name = ''; // ?
	private $webservice;
	function __construct(TranslatorInterface $translator) {
		
		global $conf;
		
		$this->endpoint = $translator->getSetting ( 'endpoint' );
		$this->username = $translator->getSetting ( 'username' );
		$this->password = $translator->getSetting ( 'password' );
		$this->proxyhost = $translator->getSetting ( 'proxyhost' );
		$this->proxyport = $translator->getSetting ( 'proxyport' );
		$this->file_profile_name = $translator->getSetting ( 'file_profile_name' );
		$this->base_url = $GLOBALS ['base_url'];
		module_load_include ( 'php', 'tmgmt_globalsight', 'lib/nusoap/lib/nusoap' );
		$this->webservice = new nusoap_client ( $GLOBALS ['base_url'] . '/' . drupal_get_path ( 'module', 'tmgmt_globalsight' ) . '/AmbassadorWebService.xml', TRUE );
		
		$this->webservice->setEndpoint ( $this->endpoint );
		
		if (isset($conf['globalsight_wsdl_apache_authtype'])) {
		$this->webservice->username = $conf['globalsight_wsdl_apache_username'];
		$this->webservice->password = $conf['globalsight_wsdl_apache_pass'];
		$this->webservice->authtype = $conf['globalsight_wsdl_apache_authtype'];
		}
	}
	
	/**
	 * Login method sends access parameters to GlobalSight and, upon success, receives access token.
	 *
	 * @return bool|mixed - FALSE: if login failed for any reason.
	 *         - Access Token: if login succeeded.
	 *        
	 * @todo : Current process does authorization on each page request. Try saving access token in database and reusing it
	 *       in successive requests.
	 */
	function login() {
		$this->webservice->setHTTPProxy ( $this->proxyhost, $this->proxyport );
		$params = array (
				'p_username' => $this->username,
				'p_password' => $this->password 
		);
		$result = $this->webservice->call ( 'login', $params );
		
		if ($this->webservice->fault) {
			if (! ($err = $this->webservice->getError ())) {
				$err = 'No error details';
			}
			return FALSE;
		}
		
		return $result;
	}
	
	/**
	 * getLocales method sends 'getFileProfileInfoEx' API request and parses a list of available languages.
	 */
	function getLocales() {
		$locales = array ();
		
		if (! ($access_token = $this->login ())) {
			return FALSE;
		}
		
		if (! ($fpId = $this->getFileProfileId($access_token))) {
			return FALSE;
		}
		
		$params = array (
				'p_accessToken' => $access_token 
		);
		$result = $this->webservice->call ( 'getFileProfileInfoEx', $params );
		$profiles = simplexml_load_string ( $result );
		
		foreach ( $profiles->fileProfile as $profile ) {
			if ($profile->id == $fpId) {
				$locales ['source'] [] = ( string ) $profile->localeInfo->sourceLocale;
				foreach ( $profile->localeInfo->targetLocale as $locale ) {
					$locales ['target'] [] = ( string ) $locale;
				}
			}
		}

		return $locales;
	}
	
	/**
	 * Method generates titles for GlobalSight by replacing unsupported characters with underlines and
	 * adding some MD5 hash trails in order to assure uniqueness of job titles.
	 *
	 * @param TMGMTJob $job
	 *        	Loaded TMGMT Job object.
	 * @return string GlobalSight job title.
	 */
	function generateJobTitle($job, $label) {
		$hash = md5 ( $this->base_url . $job->id () . time () );
		if ($job->getSourceLangcode () == "en") {
			// use post title + hash
			$post_title = str_replace ( array (
					" ",
					"\t",
					"\n",
					"\r" 
			), "_", $job->label () );
			$post_title = preg_replace ( "/[^A-Za-z0-9_]/", "", $post_title );
			$post_title = substr ( $post_title, 0, 100 ) . '_' . $hash;
		} else {
			$post_title = 'dp_' . $hash;
		}
		return $post_title;
	}
	
	/**
	 * Method generates XML document for GlobalSight based on TMGMTJob object.
	 *
	 * @param TMGMTJob $job
	 *        	Loaded TMGMT Job object.
	 * @return string XML document as per GlobalSight API specifications.
	 */
	function encodeXML($job) {
		$strings = \Drupal::service ( 'tmgmt.data' )->filterTranslatable ( $job->getData () );
    $this->processStrings($strings);
		$xml = "<?xml version='1.0' encoding='UTF-8' ?>";
		$xml .= "<fields id='" . $job->id () . "'>";
		foreach ( $strings as $key => $string ) {
			if ($string ['#translate']) {
				$xml .= "<field>";
				$xml .= "<name>$key</name>";
				$xml .= "<value><![CDATA[" . $string ['#text'] . "]]></value>";
				$xml .= "</field>";
			}
		}
		$xml .= "</fields>";
    // Uncomment to debug xml output
    // die($xml);
    // \Drupal::logger('globalsight_xml')->notice('<pre>' . htmlspecialchars(print_r($xml,1)) . '</pre>');
		return $xml;
	}
  
  /**
   * Method to process string values
   */
  function processStrings(&$strings) {
    foreach($strings as $id => $string) {
      if(!empty($string['#format']) && $string['#format'] == 'rich_text') {
        // Use line below to just render the content (i.e. conver drupal-entity tags to img tags)
        //$strings[$id]['#text'] = check_markup($string['#text'], 'rich_text')->__toString();
        
        // Process drupal entities to add lang attributes as needed
        $strings[$id]['#text'] = $this->processDrupalEntityAttributes($string['#text']);
        // Strip out xml:lang to prevent them to get duplicated
        //$strings[$id]['#text'] = str_replace('xml:lang="en-us"', '', $strings[$id]['#text']);
        $strings[$id]['#text'] = preg_replace('/(xml:lang="[a-zA-Z\-]*")/', '', $strings[$id]['#text']);
        // Remove carriage returns so they don't get encoded
        $strings[$id]['#text'] = str_replace("\r", '', $strings[$id]['#text']);
        // Normalize to fix incorrect Html
        $strings[$id]['#text'] = Html::normalize($strings[$id]['#text']);
        //$strings[$id]['#text'] = str_replace('<br>'. '<br />', $strings[$id]['#text']);

	//Remove any CDATA added as it will be wrapped into one globally
        if(strpos($strings[$id]['#text'], '[CDATA[') !== FALSE) {
          $strings[$id]['#text'] = str_replace('<!--//-->', '', $strings[$id]['#text']);
          $strings[$id]['#text'] = str_replace('<![CDATA[// >', '', $strings[$id]['#text']);
          $strings[$id]['#text'] = str_replace('<!--', '', $strings[$id]['#text']);
          $strings[$id]['#text'] = str_replace('//-->', '', $strings[$id]['#text']);
          $strings[$id]['#text'] = str_replace('<!]]>', '', $strings[$id]['#text']);
        }
 
        // Uncomment for debug, it will print the text right away in the page and not send the job to localization service
        // DON'T FORGET TO COMMENT BEFORE PUSHING!!!
        //die($strings[$id]['#text']);
      }
    }
  }
  
  /**
   * Method to process attributes for drupal entities
   */
  function processDrupalEntityAttributes(&$text) {

    if (strpos($text, 'data-entity-type') !== FALSE && (strpos($text, 'data-entity-embed-display') !== FALSE || strpos($text, 'data-view-mode') !== FALSE)) {
      $dom = Html::load($text);
      $xpath = new \DOMXPath($dom);

      foreach ($xpath->query('//drupal-entity[@data-entity-type and (@data-entity-uuid or @data-entity-id) and (@data-entity-embed-display or @data-view-mode)]') as $node) {
        /** @var \DOMElement $node */
        $entity_type = $node->getAttribute('data-entity-type');
        $entity = NULL;
        $entity_output = '';

        try {
          // Load the entity either by UUID (preferred) or ID.
          $id = NULL;
          $id_attr = '';
          $entity = NULL;
          if ($id = $node->getAttribute('data-entity-uuid')) {
            $id_attr = 'data-entity-uuid';
            $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->loadByProperties(['uuid' => $id]);
            $entity = current($entity);
          }
          else {
            $id_attr = 'data-entity-id';
            $id = $node->getAttribute('data-entity-id');
            $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($id);
          }

          if ($entity) {
            $fields = $entity->getFields();
            foreach($fields as $field_name => $field_definition) {
              $value = $entity->get($field_name)->getValue();
              if(!$node->getAttribute('lang') && strpos($field_name, 'media_lang') !== FALSE && !empty($value[0]['value'])) {
                $node->setAttribute('lang', $value[0]['value']);
                $attr = $id_attr . '="' . $id . '"';
                if(strpos($text, $attr . ' lang="') === FALSE) {
                  $text = str_replace($attr, $attr . ' lang="' . $value[0]['value'] . '" ', $text);
                }
              }
              
              // No need to add alt attribute now as that will be localized separately
              /*if($field_name == 'image') {
                $node->setAttribute('alt', $value[0]['alt']);
              }*/
            }
            
            
          }
        }catch (\Exception $e) {
          watchdog_exception('tmgmt_globalsight_drupal_entity_process', $e);
        }

      }
      
      //@TODO: probably there is a better way without the need to strip out the tags
      /*$result = preg_replace('/^<!DOCTYPE.+?>/', '', 
                  preg_replace('/<html.+?>/', '', 
                    preg_replace('/<[\/]?head.+?>/', '', 
                      str_replace( array('<html>', '</html>', '</head>', '<body>', '</body>'), array('', '', '', ''), $dom->saveHTML()))));
      return $result;*/

    }
    return $text;
  }
	
	function getFileProfileId($access_token) {
		$params = array (
			'p_accessToken' => $access_token
		);
		$result = $this->webservice->call ( 'getFileProfileInfoEx', $params);		
		$profiles = simplexml_load_string ( $result );
		
		foreach ( $profiles->fileProfile as $profile ) {
			if ($profile->name == $this->file_profile_name) {
				return (string)$profile->id;
			}			
		}
	    
		return FALSE;
	}
	
	/**
	 * Send method encodes and sends translation job to GlobalSight service.
	 *
	 * @param TMGMTJob $job
	 *        	Loaded TMGMT Job object.
	 * @param $target_locale GlobalSign
	 *        	locale code (e.g. en_US).
	 * @param $name GlobalSight
	 *        	job title.
	 * @return array Array of parameters sent with CreateJob API call.
	 */
	function send($job, $label, $target_locale, $name = FALSE) {
		if (! ($access_token = $this->login ())) {
			return FALSE;
		}
		
		if (! ($fpId = $this->getFileProfileId($access_token))) {
			return FALSE;
		}
		
		if (! $name) {
			$name = $this->generateJobTitle ( $job, $label );
		}
		
		$xml = $this->encodeXML ( $job );
		$params = array (
				'accessToken' => $access_token,
				'jobName' => $name,
				'filePath' => 'GlobalSight.xml',
				'fileProfileId' => $fpId,
				'content' => base64_encode ( $xml ) 
		);
		$response = $this->webservice->call ( 'uploadFile', $params );
		$params = array (
				'accessToken' => $access_token,
				'jobName' => $name,
				'comment' => 'Drupal GlobalSight Translation Module',
				'filePaths' => 'GlobalSight.xml',
				'fileProfileIds' => $fpId,
				'targetLocales' => $target_locale 
		);
		$response = $this->webservice->call ( 'createJob', $params );
		
		return $params;
	}
	
	/**
	 *
	 * @param string $job_name
	 *        	GlobalSight job title.
	 * @return mixed - FALSE : Ignore the status, move on...
	 *         - "PERMANENT ERROR" : There is a permanent error at GS. Cancel the job.
	 *         - API response converted to the array.
	 */
	function getStatus($job_name) {
		if (! ($access_token = $this->login ())) {
			return FALSE;
		}
		
		$params = array (
				'p_accessToken' => $access_token,
				'p_jobName' => $job_name 
		);
		$result = $this->webservice->call ( 'getStatus', $params );
		
		if ($this->webservice->fault) {
			if (! ($err = $this->webservice->getError ())) {
				$err = 'No error details';
			}
			// I do not like watchdog here! Let's try and create an error handler class in any future refactor
			\Drupal::logger ( 'tmgmt_globalsight' )->error ( "Error getting job status for !job_name. Translation job will be canceled. <br> <b>Error message:</b><br> %err", array (
					'!job_name' => $job_name,
					'%err' => $err 
			) );
			return 'PERMANENT ERROR';
		}
		
		try {
			$xml = new \SimpleXmlElement ( $result );
			return $this->xml2array ( $xml );
		} catch ( Exception $err ) {
			\Drupal::logger ( 'tmgmt_globalsight' )->error ( "Error parsing XML for !job_name. Translation job will be canceled. <br> <b>Error message:</b><br> %err", array (
					'!job_name' => $job_name,
					'%err' => $err 
			) );
			return 'PERMANENT ERROR';
		}
	}
	
	/**
	 * Method cancel requests job deletion in GlobalSight.
	 *
	 * @param string $job_name
	 *        	GlobalSight job title.
	 * @return mixed - FALSE: on any API error
	 *         - API response in form of array
	 */
	function cancel($job_name) {
		if (! ($access_token = $this->login ())) {
			return FALSE;
		}
		
		$params = array (
				'p_accessToken' => $access_token,
				'p_jobName' => $job_name 
		);
		$result = $this->webservice->call ( 'cancelJob', $params );
		
		if ($this->webservice->fault) {
			if (! ($err = $this->webservice->getError ())) {
				$err = 'No error details';
			}
			// I do not like watchdog here! Let's try and create an error handler class in any future refactor
			\Drupal::logger ( 'tmgmt_globalsight' )->notice ( "Could not cancel !job_name job. <br> <b>Error message:</b><br> %err", array (
					'!job_name' => $job_name,
					'%err' => $err 
			) );
			return FALSE;
		}
		
		$xml = new \SimpleXmlElement ( $result );
		return $this->xml2array ( $xml );
	}
	
	/**
	 * This method downloads translations for a given GlobalSight job name.
	 *
	 * @param $job_name Title
	 *        	of the GlobalSight job
	 * @return array|bool - FALSE: if API request failed due to any reason
	 *         - API response in form of array
	 */
	function receive($job_name) {
		if (! ($access_token = $this->login ())) {
			return FALSE;
		}
		
		$params = array (
				'p_accessToken' => $access_token,
				'p_jobName' => $job_name 
		);
		$result = $this->webservice->call ( "getLocalizedDocuments", $params );
		$xml = new \SimpleXmlElement ( $result );
		$download_url_prefix = $xml->urlPrefix;
		$result = $this->webservice->call ( "getJobExportFiles", $params );
		$xml = new \SimpleXmlElement ( $result );
		$paths = $xml->paths;
		$results = array ();
		$http_options = array ();
		
		// Create stream context.
		// @todo: Test this...
		if ($this->proxyhost && $this->proxyport) {
			$aContext = array (
					'http' => array (
							'proxy' => $this->proxyhost . ":" . $this->proxyport,
							'request_fulluri' => TRUE 
					) 
			);
			$http_options ['context'] = stream_context_create ( $aContext );
		}
		
		foreach ( $paths as $path ) {
			$path = trim ( ( string ) $path );
			
			// $result = drupal_http_request($download_url_prefix . '/' . $path, $http_options);
			$data = file_get_contents ( $download_url_prefix . '/' . $path );
			$xmlObject = new \SimpleXmlElement ( $data );
			foreach ( $xmlObject->field as $field ) {
				$value = ( string ) $field->value;
				$key = ( string ) $field->name;
				$results [$key] ['#text'] = $value;
			}
		}
		
		return $results;
	}
	
	/**
	 * Helper method translating GlobalSight status codes into integers.
	 */
	function code2status($code) {
		$a = array (
				0 => 'ARCHIVED',
				1 => 'DISPATCHED',
				2 => 'EXPORTED',
				3 => 'LOCALIZED',
				4 => 'CANCELED' 
		);
		
		return $a [intval ( $code )];
	}
	
	/**
	 * Helper method recursively converting xml documents to array.
	 */
	function xml2array($xmlObject, $out = array()) {
		foreach ( ( array ) $xmlObject as $index => $node ) {
			$out [$index] = (is_object ( $node )) ? $this->xml2array ( $node ) : $node;
		}
		
		return $out;
	}
	
	/**
	 * Method checks if job upload to GlobalSight succeeded.
	 *
	 * @param $jobName Title
	 *        	of the GlobalSight job
	 *        	
	 * @return bool TRUE: if job import succeeded
	 *         FALSE: if job import failed
	 */
	function uploadErrorHandler($jobName) {
		$status = $this->getStatus ( $jobName );
		$status = $status ['status'];
		
		// LEVERAGING appears to be normal status right after successful upload
		
		switch ($status) {
			
			case 'LEVERAGING' :
				return TRUE;
				break;
			
			// IMPORT_FAILED appears to be status when XML file is corrupt.
			case 'IMPORT_FAILED' :
				\Drupal::logger ( 'tmgmt_globalsight' )->error ( "Error uploading file to GlobalSight. XML file appears to be corrupt or GlobalSight server timed out. Translation job canceled.", array () );
				drupal_set_message ( t ( 'Error uploading file to GlobalSight. Translation job canceled.' ), 'error' );
				return FALSE;
				break;
			
			// UPLOADING can be normal message if translation upload did not finish, but, if unchanged for a period of time,
			// it can also be interpreted as "upload failed" message. So we need to have ugly time testing here.
			case 'UPLOADING' :
				// Wait for 5 seconds and check status again.
				sleep ( 5 );
				$revised_status = $this->getStatus ( $jobName );
				$revised_status = $revised_status ['status'];
				if ($revised_status == 'UPLOADING') {
					
					// Consolidate this messaging into an error handler and inject it as dependency
					\Drupal::logger ( 'tmgmt_globalsight' )->error ( "Error creating job at GlobalSight. Translation job canceled.", array () );
					drupal_set_message ( t ( 'Error creating job at GlobalSight. Translation job canceled.' ), 'error' );
					return FALSE;
				}
				break;
		}
		;
		
		return TRUE;
	}
}
