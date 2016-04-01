<?php
namespace MangoPay\Libraries;

use Psr\Log\LoggerInterface;

/**
 * Class to prepare HTTP request, call the request and decode the response
 */
class RestTool
{
    /**
     * Root/parent instance that holds the OAuthToken and Configuration instance
     * @var \MangoPay\MangoPayApi
     */
    private $_root;

    /**
     * Variable to flag that in request authentication data are required
     * @var bool
     */
    private $_authRequired;
    
    /**
     * Array with HTTP header to send with request
     * @var array
     */
    private $_requestHttpHeaders;
    
    /**
     * cURL handle
     * @var resource
     */
    private $_curlHandle;
    
    /**
     * Request type for current request
     * @var RequestType
     */
    private $_requestType;
    
    /**
     * Array with data to pass in the request
     * @var array
     */
    private $_requestData;
    
    /**
     * Code get from response
     * @var int
     */
    private $_responseCode;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Pagination object
     * @var MangoPay\Pagination
     */
    private $_pagination;
    
    private $_requestUrl;
    
    private static $_JSON_HEADER = 'Content-Type: application/json';
        
    /**
     * Constructor
     * @param bool $authRequired Variable to flag that in request the authentication data are required
     * @param \MangoPay\MangoPayApi Root/parent instance that holds the OAuthToken and Configuration instance
     */
    public function __construct($authRequired = true, $root)
    {
        $this->_authRequired = $authRequired;
        $this->_root = $root;
        $this->logger = $root->getLogger();
    }
    
    public function AddRequestHttpHeader($httpHeader)
    {
        if (is_null($this->_requestHttpHeaders)) {
            $this->_requestHttpHeaders = array();
        }
        
        array_push($this->_requestHttpHeaders, $httpHeader);
    }
    
    /**
     * Call request to MangoPay API
     * @param string $urlMethod Type of method in REST API
     * @param \MangoPay\Libraries\RequestType $requestType Type of request
     * @param array $requestData Data to send in request
     * @param \MangoPay\Pagination $pagination Pagination object
     * @param array Array with additional parameters to URL. Expected keys: "sort" and "filter"
     * @return object Response data
     */
    public function Request($urlMethod, $requestType, $requestData = null, $idempotencyKey = null, & $pagination = null, $additionalUrlParams = null)
    {
        $this->_requestType = $requestType;
        $this->_requestData = $requestData;
        
        $logClass = $this->_root->Config->LogClass;
        $this->logger->debug("New request");
        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('++++++++++++++++++++++ New request ++++++++++++++++++++++', '');
        }
        
        $this->BuildRequest($urlMethod, $pagination, $additionalUrlParams, $idempotencyKey);
        $responseResult = $this->RunRequest();
        

        if (!is_null($pagination)) {
            $pagination = $this->_pagination;
        }
        
        return $responseResult;
    }
    
    /**
     * Execute request and check response
     * @return object Response data
     * @throws Exception If cURL has error
     */
    private function RunRequest()
    {
        $result = curl_exec($this->_curlHandle);
        if ($result === false && curl_errno($this->_curlHandle) != 0) {
            $this->logger->error("cURL error: " . curl_error($this->_curlHandle));
            throw new Exception('cURL error: ' . curl_error($this->_curlHandle));
        }
        
        $this->_responseCode = (int) curl_getinfo($this->_curlHandle, CURLINFO_HTTP_CODE);
        
        curl_close($this->_curlHandle);

        $logClass = $this->_root->Config->LogClass;

        $this->logger->debug('Response JSON : ' . print_r($result, true));
        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('Response JSON', $result);
        }

        // FIXME This can fail hard.
        $response = json_decode($result);

        $this->logger->debug('Decoded object : ' . print_r($response, true));
        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('Response object', $response);
        }

        $this->CheckResponseCode($response);
        
        return $response;
    }
    
    /**
     * Prepare all parameter to request
     * @param String $urlMethod Type of method in REST API
     * @throws Exception If some parameters are not set
     */
    private function BuildRequest($urlMethod, $pagination, $additionalUrlParams = null, $idempotencyKey = null)
    {
        $urlTool = new UrlTool($this->_root);
        $restUrl = $urlTool->GetRestUrl($urlMethod, $this->_authRequired, $pagination, $additionalUrlParams);

        $this->_requestUrl = $urlTool->GetFullUrl($restUrl);
        $logClass = $this->_root->Config->LogClass;

        $this->logger->debug('FullUrl : ' . $this->_requestUrl);
        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('FullUrl', $this->_requestUrl);
        }
        
        $this->_curlHandle = curl_init($this->_requestUrl);
        if ($this->_curlHandle === false) {
            $this->logger->error('Cannot initialize cURL session');
            throw new Exception('Cannot initialize cURL session');
        }
        
        curl_setopt($this->_curlHandle, CURLOPT_CONNECTTIMEOUT, $this->GetCurlConnectionTimeout());
        curl_setopt($this->_curlHandle, CURLOPT_TIMEOUT, $this->GetCurlResponseTimeout());
        
        curl_setopt($this->_curlHandle, CURLOPT_RETURNTRANSFER, true);

        if ($this->_root->Config->CertificatesFilePath == '') {
            curl_setopt($this->_curlHandle, CURLOPT_SSL_VERIFYPEER, false);
        } else {
            curl_setopt($this->_curlHandle, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($this->_curlHandle, CURLOPT_CAINFO, $this->_root->Config->CertificatesFilePath);
        }
        
        if (!is_null($pagination)) {
            curl_setopt($this->_curlHandle, CURLOPT_HEADERFUNCTION, array(&$this, 'ReadResponseHeader'));
            $this->_pagination = $pagination;
        }
        
        switch ($this->_requestType) {
            case RequestType::POST:
                curl_setopt($this->_curlHandle, CURLOPT_POST, true);
                break;
            case RequestType::PUT:
                curl_setopt($this->_curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
            case RequestType::DELETE:
                curl_setopt($this->_curlHandle, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }

        $this->logger->debug('RequestType : ' . $this->_requestType);

        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('RequestType', $this->_requestType);
        }

        $httpHeaders = $this->GetHttpHeaders();
        if ($idempotencyKey != null) {
            array_push($httpHeaders, 'Idempotency-Key: ' . $idempotencyKey);
        }
        curl_setopt($this->_curlHandle, CURLOPT_HTTPHEADER, $httpHeaders);

        $this->logger->debug('HTTP Headers : ' . print_r($httpHeaders, true));

        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('HTTP Headers', $httpHeaders);
        }

        if (!is_null($this->_requestData)) {
            $this->logger->debug('RequestData object :' . print_r($this->_requestData, true));

            if ($this->_root->Config->DebugMode) {
                $logClass::Debug('RequestData object', $this->_requestData);
            }

            // encode to json if needed
            if (in_array(self::$_JSON_HEADER, $httpHeaders)) {

                // FIXME This can also fail hard and is not checked.
                $this->_requestData = json_encode($this->_requestData);
                $this->logger->debug('RequestData JSON :' . print_r($this->_requestData, true));

                if ($this->_root->Config->DebugMode) {
                    $logClass::Debug('RequestData JSON', $this->_requestData);
                }
            }

            curl_setopt($this->_curlHandle, CURLOPT_POSTFIELDS, $this->_requestData);
        }
    }
    
    /**
     * Callback to read response headers
     * @param resource $handle cURL handle
     * @param string $header Header from response
     * @return int Length of header
     */
    private function ReadResponseHeader($handle, $header)
    {
        $logClass = $this->_root->Config->LogClass;

        $this->logger->debug('Response headers :' . $header);

        if ($this->_root->Config->DebugMode) {
            $logClass::Debug('Response headers', $header);
        }
        
        if (strpos($header, 'X-Number-Of-Pages:') !== false) {
            $this->_pagination->TotalPages = (int)trim(str_replace('X-Number-Of-Pages:', '', $header));
        }
        
        if (strpos($header, 'X-Number-Of-Items:') !== false) {
            $this->_pagination->TotalItems = (int)trim(str_replace('X-Number-Of-Items:', '', $header));
        }
        
        if (strpos($header, 'Link: ') !== false) {
            $strLinks = trim(str_replace('Link:', '', $header));
            $arrayLinks = explode(',', $strLinks);
            if ($arrayLinks !== false) {
                $this->_pagination->Links = array();
                foreach ($arrayLinks as $link) {
                    $tmp = str_replace(array('<"', '">', ' rel="', '"'), '', $link);
                    $oneLink = explode(';', $tmp);
                    if (is_array($oneLink) && isset($oneLink[0]) && isset($oneLink[1])) {
                        $this->_pagination->Links[$oneLink[1]] = $oneLink[0];
                    }
                }
            }
        }
        
        return strlen($header);
    }
    
    /**
     * Get HTTP header to use in request
     * @return array Array with HTTP headers
     */
    private function GetHttpHeaders()
    {
        // return if already created...
        if (!is_null($this->_requestHttpHeaders)) {
            return $this->_requestHttpHeaders;
        }
        
        // ...or initialize with default headers
        $this->_requestHttpHeaders = array();
        
        // content type
        array_push($this->_requestHttpHeaders, self::$_JSON_HEADER);
        
        // Authentication http header
        if ($this->_authRequired) {
            $authHlp = new AuthenticationHelper($this->_root);
            array_push($this->_requestHttpHeaders, $authHlp->GetHttpHeaderKey());
        }

        return $this->_requestHttpHeaders;
    }
    
    /**
     * Check response code
     * @param object $response Response from REST API
     * @throws ResponseException If response code not OK
     */
    private function CheckResponseCode($response)
    {
        if ($this->_responseCode != 200) {
            if (isset($response) && is_object($response) && isset($response->Message)) {
                $error = new Error();
                $error->Message = $response->Message;
                $error->Errors = property_exists($response, 'Errors')
                        ? $response->Errors
                        : property_exists($response, 'errors') ? $response->errors : null;
                throw new ResponseException($this->_requestUrl, $this->_responseCode, $error);
            } else {
                throw new ResponseException($this->_requestUrl, $this->_responseCode);
            }
        }
    }
    
    /**
     * Get cURL connection timeout to use in request
     * @return int Time in seconds
     */
    private function GetCurlConnectionTimeout()
    {
        return (int) max($this->_root->Config->CurlConnectionTimeout, 0);
    }
    
    /**
     * Get cURL response timeout to use in request
     * @return int Time in seconds
     */
    private function GetCurlResponseTimeout()
    {
        return (int) max($this->_root->Config->CurlResponseTimeout, 0);
    }
}
