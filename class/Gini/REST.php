<?php

namespace Gini;

class REST
{
    private $_url;
    private $_path;
    private $_cookie;
    private $_version;
    private $_name;
    private $_header = [];
    private $_uniqid = 1;

    private static $_RESTs = [];
    public static function of($name, $cookie = null, $header = []) {
        if (!self::$_RESTs[$name]) {
            $conf = \Gini\Config::get('app.rest');
            $rest = IoC::construct('\Gini\REST', $conf[$name]['url'], $conf[$name]['path'], $conf[$name]['version'], $cookie, $name, $header);
            self::$_RESTs[$name] = $rest;
        }
        return self::$_RESTs[$name];
    }

    public function __construct($url, $path = null, $version = null, $cookie = null, $name = null, $header = [])
    {
        $this->_url = $url;
        $this->_path = $path;
        $this->_version = $_version;
        $this->_name = $name;
        $this->_cookie = $cookie ?: IoC::construct('\Gini\REST\Cookie');
        $this->_header = (array) $header;
    }

    public function __get($name)
    {
        return IoC::construct('\Gini\REST', $this->_url, $this->_path ? $this->_path.'/'.$name : $name, $this->_version, $this->_cookie, $this->_header);
    }

    public function __call($method, $params)
    {
        if ($method === __FUNCTION__) {
            return;
        }

        if ($this->_path) {
            $path = '/' . $this->_path;
        }
        $path .= '/' . array_shift($params);
        
        $restTimeout = Config::get('rest.timeout');
        $timeout = $restTimeout[$path] ?: $restTimeout['default'];

        $raw_data = $this->action([
            'requset' => strtoupper($method),
            'params' => $params[0],
            'path' => $path,
        ], $timeout);

        \Gini\Logger::of('core')->debug('REST <= {data}', ['data' => $raw_data]);

        $data = @json_decode($raw_data, true);
        if (isset($data['error'])) {
            $message = sprintf('remote error: %s', $data['error']['message']);
            $code = $data['error']['code'];
            throw IoC::construct('\Gini\REST\Exception', $message, $code);
        } 
        elseif (is_null($data)) {
            $message = sprintf('unknown error with raw data: %s', $raw_data ?: '(null)');
            throw IoC::construct('\Gini\REST\Exception', $message, 404);
        }

        return $data;
    }

    public function setHeader(array $header)
    {
        // if format is ['xx: xx'], convert it to ['xx' => 'xx']
        $kh = [];
        foreach ($header as $k => $h) {
            if (is_numeric($k)) {
                list($k, $v) = explode(':', $h, 2);
                $kh[trim($k)]=trim($v);
            } else {
                $kh[$k] = $h;
            }
        }
        $this->_header = array_merge($this->_header, $kh);
    }

    public function action($post_data, $timeout = 5)
    {
        $cookie_file = $this->_cookie->file;

        $ch = curl_init();

        $this->_header['Accept'] = "application/{$this->_url}+json; version={$this->_version}"; 

        $conf = \Gini\Config::get('app.rest')[$this->_name];

        if ($conf['client_id'] && $conf['client_secret']) {
            $authorization = 'HMAC '.$conf['client_id'].':'.base64_encode(hash_hmac('sha1', $conf['client_secret'], $conf['client_id']));
            $this->_header['Authorization'] = $authorization;
        }
        
        // convert to Key: Value format
        $header = function($array = []) {
            $response = [];
            foreach (array_merge($array, $this->_header) as $key => $value) {
                $response[] = "$key: $value";
            }
            return $response;
        };
        $query = (string)http_build_query($post_data['params'] ? : []);

        $options = [
            CURLOPT_COOKIEJAR => $cookie_file,
            CURLOPT_COOKIEFILE => $cookie_file,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_AUTOREFERER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ? : 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)',
        ];
        curl_setopt_array($ch, $options);

        switch ($post_data['requset']) {
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                $this->_header['Content-Type'] = 'application/x-www-form-urlencoded';
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->_url . '/' . $post_data['path'],
                    CURLOPT_POSTFIELDS => $query,
                    CURLOPT_CUSTOMREQUEST => $post_data['requset']
                ]);
                break;
            case 'POST':
                parse_str($query, $output);
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->_url . '/' . $post_data['path'],
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $output,
                ]);
                break;
            default:
                $this->_header['Content-Type'] = 'application/json';
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => $post_data['requset'],
                    CURLOPT_URL => $this->_url . '/' . $post_data['path'] . '?' . $query,
                ]);
                break;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header());

        \Gini\Logger::of('core')->debug('REST => {url}: {data}', ['url' => $this->_url, 'data' => $post_data]);

        $data = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            $message = curl_error($ch);
            curl_close($ch);

            \Gini\Logger::of('core')->error('REST cURL error: {url}: {message}', ['url' => $this->_url, 'message' => $message]);
            throw IoC::construct('\Gini\REST\Exception', "transport error: $message", -32300);
        }

        curl_close($ch);

        return $data;
    }
}
