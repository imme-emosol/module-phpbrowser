<?php

declare(strict_types=1);

namespace Codeception\Module;

use Closure;
use Codeception\Lib\Connector\Guzzle;
use Codeception\Lib\InnerBrowser;
use Codeception\Lib\Interfaces\MultiSession;
use Codeception\Lib\Interfaces\Remote;
use Codeception\TestInterface;
use Codeception\Util\Uri;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\BrowserKit\AbstractBrowser;

/**
 * Uses [Guzzle](https://docs.guzzlephp.org/en/stable/) to interact with your application over CURL.
 * Module works over CURL and requires **PHP CURL extension** to be enabled.
 *
 * Use to perform web acceptance tests with non-javascript browser.
 *
 * If test fails stores last shown page in 'output' dir.
 *
 * ## Status
 *
 * * Maintainer: **davert**
 * * Stability: **stable**
 * * Contact: codeception@codeception.com
 *
 *
 * ## Configuration
 *
 * * url *required* - start url of your app
 * * headers - default headers are set before each test.
 * * handler (default: curl) -  Guzzle handler to use. By default curl is used, also possible to pass `stream`, or any valid class name as [Handler](http://docs.guzzlephp.org/en/latest/handlers-and-middleware.html#handlers).
 * * middleware - Guzzle middlewares to add. An array of valid callables is required.
 * * curl - curl options
 * * cookies - ...
 * * auth - ...
 * * verify - ...
 * * .. those and other [Guzzle Request options](http://docs.guzzlephp.org/en/latest/request-options.html)
 *
 *
 * ### Example (`acceptance.suite.yml`)
 *
 *     modules:
 *        enabled:
 *            - PhpBrowser:
 *                url: 'http://localhost'
 *                auth: ['admin', '123345']
 *                curl:
 *                    CURLOPT_RETURNTRANSFER: true
 *                cookies:
 *                    cookie-1:
 *                        Name: userName
 *                        Value: john.doe
 *                    cookie-2:
 *                        Name: authToken
 *                        Value: 1abcd2345
 *                        Domain: subdomain.domain.com
 *                        Path: /admin/
 *                        Expires: 1292177455
 *                        Secure: true
 *                        HttpOnly: false
 *
 *
 * All SSL certification checks are disabled by default.
 * Use Guzzle request options to configure certifications and others.
 *
 * ## Public API
 *
 * Those properties and methods are expected to be used in Helper classes:
 *
 * Properties:
 *
 * * `guzzle` - contains [Guzzle](http://guzzlephp.org/) client instance: `\GuzzleHttp\Client`
 * * `client` - Symfony BrowserKit instance.
 *
 */
class PhpBrowser extends InnerBrowser implements Remote, MultiSession
{
    /**
     * @var string[]
     */
    protected $requiredFields = ['url'];

    /**
     * @var array
     */
    protected $config = [
        'headers' => [],
        'verify' => false,
        'expect' => false,
        'timeout' => 30,
        'curl' => [],
        'refresh_max_interval' => 10,
        'handler' => 'curl',
        'middleware' => null,

        // required defaults (not recommended to change)
        'allow_redirects' => false,
        'http_errors' => false,
        'cookies' => true,
    ];

    /**
     * @var string[]
     */
    protected array $guzzleConfigFields = [
        'auth',
        'proxy',
        'verify',
        'cert',
        'query',
        'ssl_key',
        'proxy',
        'expect',
        'version',
        'timeout',
        'connect_timeout'
    ];

    /**
     * @var Guzzle
     */
    public ?AbstractBrowser $client = null;

    public ?GuzzleClient $guzzle = null;

    public function _initialize()
    {
        $this->_initializeSession();
    }

    public function _before(TestInterface $test)
    {
        if (!$this->client) {
            $this->client = new Guzzle();
        }

        $this->_prepareSession();
    }

    public function _getUrl()
    {
        return $this->config['url'];
    }

    /**
     * Alias to `haveHttpHeader`
     */
    public function setHeader(string $name, string $value): void
    {
        $this->haveHttpHeader($name, $value);
    }

    public function amHttpAuthenticated($username, $password): void
    {
        $this->client->setAuth($username, $password);
    }

    public function amOnUrl($url): void
    {
        $host = Uri::retrieveHost($url);
        $config = $this->config;
        $config['url'] = $host;
        $this->_reconfigure($config);
        $page = substr($url, strlen($host));
        if ($page === '') {
            $page = '/';
        }

        $this->debugSection('Host', $host);
        $this->amOnPage($page);
    }

    public function amOnSubdomain($subdomain): void
    {
        $url = $this->config['url'];
        $url = preg_replace('#(https?://)(.*\.)(.*\.)#', "$1$3", $url); // removing current subdomain
        $url = preg_replace('#(https?://)(.*)#', sprintf('$1%s.$2', $subdomain), $url);
         // inserting new
        $config = $this->config;
        $config['url'] = $url;
        $this->_reconfigure($config);
    }

    protected function onReconfigure()
    {
        $this->_prepareSession();
    }

    /**
     * Low-level API method.
     * If Codeception commands are not enough, use [Guzzle HTTP Client](http://guzzlephp.org/) methods directly
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->executeInGuzzle(function (\GuzzleHttp\Client $client) {
     *      $client->get('/get', ['query' => ['foo' => 'bar']]);
     * });
     * ```
     *
     * It is not recommended to use this command on a regular basis.
     * If Codeception lacks important Guzzle Client methods, implement them and submit patches.
     *
     * @return mixed
     */
    public function executeInGuzzle(Closure $function)
    {
        return $function($this->guzzle);
    }

    /**
     * @return int|string
     */
    public function _getResponseCode()
    {
        return $this->getResponseStatusCode();
    }

    public function _initializeSession(): void
    {
        // independent sessions need independent cookies
        $this->client = new Guzzle();
        $this->_prepareSession();
    }

    public function _prepareSession(): void
    {
        $defaults = array_intersect_key($this->config, array_flip($this->guzzleConfigFields));
        $curlOptions = [];

        foreach ($this->config['curl'] as $key => $val) {
            if (defined($key)) {
                $curlOptions[constant($key)] = $val;
            }
        }

        $this->headers = $this->config['headers'];
        $this->setCookiesFromOptions();

        $defaults['base_uri'] = $this->config['url'];
        $defaults['curl'] = $curlOptions;
        $handler = Guzzle::createHandler($this->config['handler']);
        if ($handler && is_array($this->config['middleware'])) {
            foreach ($this->config['middleware'] as $middleware) {
                $handler->push($middleware);
            }
        }

        $defaults['handler'] = $handler;
        $this->guzzle = new GuzzleClient($defaults);

        $this->client->setRefreshMaxInterval($this->config['refresh_max_interval']);
        $this->client->setClient($this->guzzle);
    }

    public function _backupSession(): array
    {
        return [
            'client' => $this->client,
            'guzzle' => $this->guzzle,
            'crawler' => $this->crawler,
            'headers' => $this->headers,
        ];
    }

    public function _loadSession($session): void
    {
        foreach ($session as $key => $val) {
            $this->$key = $val;
        }
    }

    public function _closeSession($session = null): void
    {
        unset($session);
    }
}
