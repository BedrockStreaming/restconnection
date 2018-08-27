<?php

namespace RESTConnection;

/**
 * Client class.
 */
class Client
{
    const GET    = 0; // load/retrieve
    const POST   = 1; // create
    const PUT    = 2; // update (replacing old entity, removing undefined fields)
    const DELETE = 3; // delete
    const PATCH  = 4; // update (modifying fields from old entity)

    protected static $acceptedVerbs = array (
        self::GET,
        self::POST,
        self::PUT,
        self::DELETE,
        self::PATCH
    );

    protected $serviceUrl;
    protected $serviceUser;
    protected $servicePassword;
    protected $serviceTimeout = 900;
    protected $serviceRequestUserAgent;
    protected $serviceRequestHeader;

    protected $compatibilityMode = false;

    protected $lastResponseHeader;
    protected $lastResponseBody;
    protected $lastResponseInfo;
    protected $lastResponseError;

    /**
     * Constructor.
     *
     * @param string $serviceUrl
     * @param array  $serviceRequestHeader
     * @param string $serviceUser
     * @param string $servicePassword
     *
     * @return void
     */
    public function __construct($serviceUrl, $serviceRequestHeader = array(), $serviceUser = null, $servicePassword = null)
    {
        $this->serviceUrl           = $serviceUrl;
        $this->serviceRequestHeader = $serviceRequestHeader;
        $this->serviceUser          = $serviceUser;
        $this->servicePassword      = $servicePassword;
    }

    /**
     * Set service request header
     *
     * @param array $requestHeader
     *
     * @return void
     */
    public function setServiceRequestHeader($requestHeader)
    {
        $this->serviceRequestHeader = $requestHeader;
    }

    /**
     * Get service request header
     *
     * @return array
     */
    public function getServiceRequestHeader()
    {
        return $this->serviceRequestHeader;
    }

    /**
     * Set service request user agent.
     *
     * @param string $requestUserAgent
     *
     * @return void
     */
    public function setServiceRequestUserAgent($requestUserAgent)
    {
        $this->serviceRequestUserAgent = $requestUserAgent;
    }

    /**
     * Get service request user agent.
     *
     * @return string
     */
    public function getServiceRequestUserAgent()
    {
        return $this->serviceRequestUserAgent;
    }

    /**
     * Set service timeout.
     *
     * @param integer $timeout
     *
     * @return void
     */
    public function setServiceTimeout($timeout)
    {
        $this->serviceTimeout = $timeout;
    }

    /**
     * Get service timeout.
     *
     * @return integer
     */
    public function getServiceTimeout()
    {
        return $this->serviceTimeout;
    }

    /**
     * Set service url.
     *
     * @param string $url
     *
     * @return void
     */
    public function setServiceUrl($url)
    {
        $this->serviceUrl = $url;
    }

    /**
     * Get service url.
     *
     * @return string
     */
    public function getServiceUrl()
    {
        return $this->serviceUrl;
    }

    /**
     * Set service credentials.
     *
     * @param string $userName
     * @param string $password
     *
     * @return @void
     */
    public function setServiceCredentials($userName, $password)
    {
        $this->serviceUser     = $userName;
        $this->servicePassword = $password;
    }

    /**
     * Set compatibility mode.
     *
     * @param boolean $mode
     *
     * @return void
     */
    public function setCompatibilityMode($mode)
    {
        $this->compatibilityMode = $mode;
    }

    /**
     * Get compatibility mode.
     *
     * @return boolean
     */
    public function getCompatibilityMode()
    {
        return $this->compatibilityMode;
    }

    /**
     * Flush last response.
     *
     * @return void
     */
    protected function flushLastResponse()
    {
        $this->lastResponseHeader = null;
        $this->lastResponseBody   = null;
        $this->lastResponseInfo   = null;
        $this->lastResponseError  = null;
    }

    /**
     * Get response header.
     *
     * @return string
     */
    public function getResponseHeader()
    {
        return $this->lastResponseHeader;
    }

    /**
     * Get response body.
     *
     * @return string
     */
    public function getResponseBody()
    {
        return $this->lastResponseBody;
    }

    /**
     * Get response info.
     *
     * @return array
     */
    public function getResponseInfo()
    {
        return $this->lastResponseInfo;
    }

    /**
     * Get last error.
     *
     * @return string
     */
    public function getLastError()
    {
        return $this->lastResponseError;
    }

    /**
     * Get last status code.
     *
     * @return integer
     */
    public function getLastStatusCode()
    {
        if (isset($this->lastResponseInfo)) {
            return $this->lastResponseInfo['http_code'];
        }

        return null;
    }

    /**
     * Request.
     *
     * @param string  $ressourceUrl
     * @param array   $params
     * @param integer $verb
     * @param integer $overridingVerb
     *
     * @return boolean
     */
    public function request($ressourceUrl, $params = null, $verb = self::GET, $overridingVerb = null)
    {
        // flush last response
        $this->flushLastResponse();

        // Compatibility mode if server only supports GET/POST or firewall blocks some verbs
        if ($this->compatibilityMode && $verb != self::GET && $verb != self::POST) {
            $overridingVerb = $verb;
        }

        // Override specified verb if needed (might be needed to force GET method as a POST if parameters are too long)
        if (!is_null($overridingVerb)) {
            if ( !in_array($overridingVerb, self::$acceptedVerbs)) {
                throw new InvalidArgumentException(sprintf("Unsupported overriding HTTP Verb: %s", $overridingVerb));
            }

            // the overriding verb is valid, forcing current $verb to POST if user forgot to do so
            $verb = self::POST;
        }

        $curlHandler = curl_init();

        $this->setAuth($curlHandler);
        $this->setCurlOpts($curlHandler, $ressourceUrl, $overridingVerb);

        try {
            switch ($verb) {
                case self::GET:
                    $this->executeGet($curlHandler);
                    break;
                case self::POST:
                    $this->executePost($curlHandler, $params);
                    break;
                case self::PUT:
                    $this->executePut($curlHandler, $params);
                    break;
                case self::DELETE:
                    $this->executeDelete($curlHandler, $params);
                    break;
                case self::PATCH:
                    $this->executePatch($curlHandler, $params);
                    break;
                default:
                    throw new InvalidArgumentException(sprintf("Unsupported HTTP Verb: %s", $verb));
                    break;
            }
        } catch (InvalidArgumentException $e) {
            curl_close($curlHandler);
            throw $e;
        } catch (Exception $e) {
            curl_close($curlHandler);
            throw $e;
        }

        return !is_null($this->lastResponseBody) && $this->getLastStatusCode()>=200 && $this->getLastStatusCode()<300;
    }

    /**
     * Format data.
     *
     * @param array $data
     *
     * @return array
     */
    protected function formatData ($data)
    {
        // if passed data is an array, urlencode it (val1=foo&val2=bar...)
        if (is_array($data)) {
            return http_build_query($data, '', '&');
        }

        return $data;
    }

    /**
     * Execute get.
     *
     * @param resource $curlHandler
     *
     * @return void
     */
    protected function executeGet($curlHandler)
    {
        curl_setopt($curlHandler, CURLOPT_HTTPGET, true);  // reset http get just in case

        $this->doExecute($curlHandler);
    }

    /**
     * Execute post.
     *
     * @param resource $curlHandler
     * @param array    $data
     *
     * @return void
     */
    protected function executePost ($curlHandler, $data)
    {
        $req = $data;

        if (is_array($data)) {
            $req = $this->flattenArray($data);
        }

        curl_setopt($curlHandler, CURLOPT_POST, true);
        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, $req);

        $this->doExecute($curlHandler);
    }

    /**
    * Execute pute.
    *
    * @param resource $curlHandler
    * @param array    $data
    *
    * @return void
    */
    protected function executePut ($curlHandler, $data)
    {
        curl_setopt($curlHandler, CURLOPT_PUT, true);

        $this->executeStreamData($curlHandler, $data);
    }

    /**
     * Execute delete.
     *
     * @param resource $curlHandler
     * @param array    $data
     *
     * @return void
     */
    protected function executeDelete ($curlHandler, $data)
    {
        curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, 'DELETE');

        if (!is_null($data)) {
            $this->executeStreamData($curlHandler, $data);
        } else {
            $this->doExecute($curlHandler);
        }
    }

    /**
    * Execute patch.
    *
    * @param resource $curlHandler
    * @param array    $data
    *
    * @return void
    */
    protected function executePatch ($curlHandler, $data)
    {
        $req = $this->formatData($data);

        curl_setopt($curlHandler, CURLOPT_POSTFIELDS, $req);
        curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, 'PATCH');

        $this->doExecute($curlHandler);
    }

    /**
     * Execute stream data.
     *
     * @param resource $curlHandle
     * @param array    $data
     *
     * @return void
     */
    protected function executeStreamData($curlHandle, $data)
    {
        $req = $this->formatData($data);

        $requestLength = strlen($req);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $req);
        rewind($fh);

        curl_setopt($curlHandle, CURLOPT_INFILE, $fh);
        curl_setopt($curlHandle, CURLOPT_INFILESIZE, $requestLength);

        $this->doExecute($curlHandle);

        fclose($fh);
    }

    /**
    * Execute.
    *
    * @param resource &$curlHandle
    *
    * @return void
    */
    protected function doExecute (&$curlHandle)
    {
        $response = curl_exec($curlHandle);

        if (!$response) {
            $this->lastResponseError = (sprintf("%s (%s)", curl_error($curlHandle), ""));
        } else {
            $this->lastResponseError  = "No errors";
            $this->lastResponseInfo   = curl_getinfo($curlHandle);
            $headerSize               = $this->lastResponseInfo['header_size'];
            $this->lastResponseHeader = substr($response, 0, $headerSize);
            $this->lastResponseBody   = substr($response, $headerSize);
        }

        $status = $this->getLastStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->lastResponseError = (sprintf("Error %d (%s) for %s", $status, self::getStatusCodeMessage($status), $this->lastResponseInfo['url']));
        }

        curl_close($curlHandle);
    }

    /**
     * Set cURL Options.
     *
     * @param resource &$curlHandle
     * @param string   $ressourceUrl
     * @param string   $overridingVerb
     *
     * @return void
     */
    protected function setCurlOpts(&$curlHandle, $ressourceUrl, $overridingVerb)
    {
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, $this->serviceTimeout);
        curl_setopt($curlHandle, CURLOPT_URL, $this->serviceUrl.$ressourceUrl);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HEADER, true);

        $header = $this->serviceRequestHeader;

        if (!is_null($overridingVerb)) {
            $header[] = "X-HTTP-Method-Override: ".$overridingVerb;
        }

        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $this->serviceRequestHeader);

        // add useragent if requested
        if (!is_null($this->serviceRequestUserAgent)) {
            curl_setopt($curlHandle, CURLOPT_USERAGENT, $this->serviceRequestUserAgent);
        }
    }

    /**
    * Set Auth.
    *
    * @param resource &$curlHandle
    *
    * @return  void
    */
    protected function setAuth(&$curlHandle)
    {
        if (!is_null($this->serviceUser) && !is_null($this->servicePassword)) {
            curl_setopt($curlHandle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curlHandle, CURLOPT_USERPWD, $this->serviceUser.':'.$this->servicePassword);
        }
    }

    /**
     * Flatten array
     *
     * @param array  $array
     * @param string $depth
     *
     * @return array
     */
    protected function flattenArray($array, $depth = '')
    {
        $result = array();

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $suffix = $depth != '' ? '][' : '[';
                $result = $result + $this->flattenArray($value, $depth . $key . $suffix);
            } else {
                $suffix = $depth != '' ? ']' : '';
                $result[$depth.$key . $suffix] = $value;
            }
        }

        return $result;
    }

    /**
     * Get status code message.
     *
     * @param integer $status
     *
     * @return string
     */
    public static function getStatusCodeMessage($status)
    {
        $codes = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => '(Unused)',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported'
        );

        return (isset($codes[$status])) ? $codes[$status] : '';
    }
}
