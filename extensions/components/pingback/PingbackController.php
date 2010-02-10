<?php

require_once 'OntoWiki/Controller/Component.php';
require_once 'Zend/XmlRpc/Server.php';
require_once 'Zend/XmlRpc/Server/Exception.php';

class PingbackController extends OntoWiki_Controller_Component
{
	public function pingAction()
    {
        $this->_logInfo('Pingback Server Init.'); 
		
		// Create XML RPC Server
		$server = new Zend_XmlRpc_Server();
		$server->setClass($this, 'pingback');
		
		// Let the server handle the RPC calls.
		$response = $this->getResponse();
        $response->setBody($server->handle());
        $response->sendResponse();
		exit;
    }
    
    /**
	 *
	 * @param string      $sourceUri
	 * @param string      $targetUri
	 *
	 * @return int
	 */
	public function ping($sourceUri, $targetUri) 
	{	
		$this->_logInfo('Method ping was called.');

		// 1. Try to dereference the source URI.
		require_once 'Zend/Http/Client.php';
        $client = new Zend_Http_Client($sourceUri, array(
            'maxredirects'  => 10,
            'timeout'       => 30
        ));
		// If a relation URI is given, we try application/rdf+xml first, if not we use text/html
		$relationUri = null;
		if ($relationUri !== null) {
		    $client->setHeaders('Accept', 'application/rdf+xml');
		} else {
		    $client->setHeaders('Accept', 'text/html');
		}
		
		
		try {
		    $response = $client->request();
		} catch (Exception $e) {
		    $this->_logError('Error:' . $e->getMessage());
		    return 0;
		}
        if ($response->getStatus() === 200) {
            if ($relationUri !== null) {
                // TODO handle rdf/xml
            } else {
                $htmlDoc = new DOMDocument();
                $result = @$htmlDoc->loadHtml($response->getBody());
                $aElements = $htmlDoc->getElementsByTagName('a');

                $success = false;
                foreach ($aElements as $aElem) {
                    $a  = $aElem->getAttribute('href');
                    if (strtolower($a) === $targetUri) {
                        $success = true;
                        break;
                    }
                }
                
                if (!$success) {
                    $this->_logError('0x0011');
                    return 0x0011;
                }
            }
        } else {
            $this->_logError('0x0010');
            return 0x0010;
        }
		
        
        // 2. Now we not that sourceUri exists and that the dereferenced content contains a link to targetUri.
        // Next step is to check, whether target URI exists (at least one statement).
        $store = Erfurt_App::getInstance()->getStore();
        $sparql = 'ASK WHERE { <' . $targetUri . '> ?p ?o . }';
        require_once 'Erfurt/Sparql/SimpleQuery.php';
		$query = Erfurt_Sparql_SimpleQuery::initWithString($sparql);
		try {
		    $result = $store->sparqlQuery($query);
		} catch (Excpetion $e) {
		    $this->_logError('Error:' . $e->getMessage());
		}
        
	    if (!$result) {
	        $this->_logError('0x0020');
	        return 0x0020;
	    } 
        // Next step is to check, whether the pingback statement already exists and if not to add it.
		if ($this->_pingbackExists($sourceUri, $targetUri, $relationUri)) {
		    $this->_logError('0x0030');
		    return 0x0030;
		}
		
		$this->_addPingback($sourceUri, $targetUri);
   
		// pingback done
		//$error = "Thanks! Pingback from ".$sourceURI." to ".$targetURI." registered";
		
		$this->_logInfo('Pingback registered.');
	}
	
	protected function _addPingback($sourceUri, $targetUri, $relationUri = null) 
	{
		$store = Erfurt_App::getInstance()->getStore();
		$model = $store->getModel($this->_privateConfig->pingback_model);
		
// TODO use configurable relation uris...
		if ($relationUri === null) {
		    // Use default...
		    $model->addStatement(
    			$sourceUri,
    			'http://rdfs.org/sioc/ns#links_to',
    			array('value' => $targetUri, 'type' => 'uri')
    		);
		} else {
// TODO use inverse?
		    $model->addStatement(
    			$targetUri,
    		    $relationUri,
    			array('value' => $sourceUri, 'type' => 'uri')
    		);
		}
	}

	protected function _logError($msg) 
	{
	    $owApp = OntoWiki::getInstance(); 
	    $logger = $owApp->logger;
	    
	    if (is_array($msg)) {
	        $logger->debug('Pingback Plugin Error: ' . print_r($msg, true));
	    } else {
	        $logger->debug('Pingback Plugin Error: ' . $msg);
	    }
	}
	
	protected function _logInfo($msg) 
	{
	    $owApp = OntoWiki::getInstance(); 
	    $logger = $owApp->logger;
	    
	    if (is_array($msg)) {
	        $logger->debug('Pingback Plugin Info: ' . print_r($msg, true));
	    } else {
	        $logger->debug('Pingback Plugin Info: ' . $msg);
	    }
	}
	
	protected function _pingbackExists($sourceUri, $targetUri, $relationUri = null)
    {
// TODO use confgurable predicate uris
// TODO search for inverse uris if relation is given
        $store = Erfurt_App::getInstance()->getStore();
		$model = $store->getModel($this->_privateConfig->pingback_model);

		// Check if it already was pinged.
		if ($relationUri === null) {
		    $relationUri = 'http://rdfs.org/sioc/ns#links_to';
		    
		    $sparql = 'SELECT ?pingback
    			WHERE {
    				?pingback <' . $relationUri . '> <' . $targetUri . '>.
    			}
    			LIMIT 1';
		} else {
		    $sparql = 'SELECT ?pingback
    			WHERE {
    				<' . $targetUri . '> <' . $relationUri . '> ?pingback.
    			}
    			LIMIT 1';
		}
	
		require_once 'Erfurt/Sparql/SimpleQuery.php';
		$query = Erfurt_Sparql_SimpleQuery::initWithString($sparql);
		$result = $model->sparqlQuery($query);
		if ($result[0]['pingback'] === $sourceUri) {
			return true;
		}
		
        return false;
    }
}
