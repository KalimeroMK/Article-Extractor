<?php declare(strict_types=1);

namespace Goose;

use Goose\Exceptions\MalformedURLException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client
 *
 * @package Goose
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 */
class Client {
    /** @var Configuration */
    protected $config;

    /**
     * @param  array  $config
     */
    public function __construct(array $config = []) {
        $this->config = new Configuration($config);
    }

    /**
     * @param string $name
     * @param  array  $arguments
     *
     * @return mixed
     */
    public function __call(string $name, array $arguments) {
        if (method_exists($this->config, $name)) {
            return call_user_func_array(array($this->config, $name), $arguments);
        }

        return null;
    }

    /**
     * @param  string  $url
     * @param  string|null  $rawHTML
     *
     * @return Article
     * @throws MalformedURLException
     * @throws GuzzleException
     */
    public function extractContent(string $url, string $rawHTML = null): ?Article {
        $crawler = new Crawler($this->config);
        return $crawler->crawl($url, $rawHTML);
    }
}