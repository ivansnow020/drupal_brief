<?php

namespace Drupal\sample_api_fetcher\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Controller for the External API page.
 */
class ApiPageController extends ControllerBase {

  protected $httpClient;
  protected $cache;

  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache_backend) {
    $this->httpClient = $http_client;
    $this->cache = $cache_backend;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('cache.default')
    );
  }

  /**
   * Response builder, retrieves data, and stores it in cache for 6 hours.
   */
  public function build() {
    $cid = 'sample_api_fetcher:external_data';
    $data = NULL;
    $six_hours_in_seconds = 6 * 3600;

    // Try to get the data from cache first.
    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
    }
    else {
      // If not in cache, fetch from the API.
      try {
        $apiUrl = 'https://jsonplaceholder.typicode.com/users'; // Example API
        $response = $this->httpClient-> get($apiUrl, ['verify' => false]);
        $data = json_decode($response->getBody()->getContents());
        
        
        // Store the fresh data in the cache for 6 hours.
        $this->cache->set($cid, $data, time() + $six_hours_in_seconds);

      } catch (RequestException $e) {
        // Log the error and display a message.
        $this->getLogger('sample_api_fetcher')->error($e->getMessage());
        return [
          '#markup' => $this->t('There was an error retrieving the data.'),
        ];
      }
    }

    // Pass the data 
    return [
      '#theme' => 'sample_api_fetcher_template',
      '#data' => $data,
      '#cache' => [
        'keys' => ['sample_api_fetcher_page'],
        'contexts' => ['url'],
        'tags' => ['sample_api_fetcher_data'],
        'max-age' => $six_hours_in_seconds,
      ],
    ];
  }
}