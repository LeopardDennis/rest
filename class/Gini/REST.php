<?php
/**
 * Created by PhpStorm.
 * User: tangxiaowen
 * Date: 17/7/14
 * Time: 下午3:18
 */
namespace Gini;

use GuzzleHttp\Client;

class REST
{
    private $_url;
    private $_path;
    private $_cookie;
    private $_version;
    private $_name;
    private $_header = [];
    private $_client;


    public function __construct($url, $path = null, $header = [], $version = null, $name = null)
    {
        $this->_url = $url;
        $this->_path = $path;
        $this->_version = $version;
        $this->_name = $name;

        $this->_header = (array) $header;

        $this->_header['Accept'] = "application/{$this->_url}+json; version={$this->_version}";

        $this->_header['User-Agent'] = $_SERVER['HTTP_USER_AGENT'] ? : 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)';

        $conf = Config::get('app.rest')[$this->_name];

        if ($conf['client_id'] && $conf['client_secret']) {
            $authorization = 'HMAC '.$conf['client_id'].':'.base64_encode(hash_hmac('sha1', $conf['client_secret'], $conf['client_id']));
            $this->_header['Authorization'] = $authorization;
        }

        $this->_client = new Client([
            'headers' => $this->_header,
            'cookies' => $this->_cookie
        ]);
    }

    public function __get($name)
    {
        return IoC::construct('\Gini\REST', $this->_url, $this->_path ? $this->_path.'/'.$name : $name, $this->_header, $this->_version);
    }

    public function __call($method, $params)
    {
        if ($method === __FUNCTION__) {
            return;
        }

        $path = '';
        if ($this->_path) {
            $path .= '/' . $this->_path;
        }
        $path .= '/' . array_shift($params);


        $restTimeout = Config::get('rest.timeout');
        $timeout = $restTimeout[$path] ?: $restTimeout['default'];

        $raw_data = $this->action([
            'request' => strtoupper($method),
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

    public function action($post_data, $timeout = 5)
    {
        Logger::of('core')->debug('REST => {url}: {data}', ['url' => $this->_url, 'data' => $post_data]);

        switch ($post_data['request']) {
            case 'PUT':
                $response = $this->_client->put($this->_url . $post_data['path'], [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'timeout' => $timeout,
                    'form_params' => $post_data['params'] ?: [],
                ]);
                break;
            case 'PATCH':
                $response = $this->_client->patch($this->_url . $post_data['path'], [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'timeout' => $timeout,
                    'form_params' => $post_data['params'] ?: [],
                ]);
                break;
            case 'DELETE':
                $response = $this->_client->delete($this->_url . $post_data['path'], [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'timeout' => $timeout,
                    'form_params' => $post_data['params'] ?: [],
                ]);
                break;
            case 'POST':
                $response = $this->_client->post($this->_url . $post_data['path'], [
                    'form_params' => $post_data['params'] ?: [],
                    'timeout' => $timeout
                ]);
                break;
            default:
                $response = $this->_client->get($this->_url . $post_data['path'], [
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => $timeout,
                    'query' => $post_data['params'] ?: [],
                ]);
                break;
        }

        return $response->getBody()->getContents();
    }
}