<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api_pantheon\Endpoint;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Utility\SchemaPoster;
use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Psr7\Request;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Core\Query\Result\ResultInterface;

/**
 * A Drush command file.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class SearchApiPantheonCommands extends DrushCommands
{

  /**
   * search_api_pantheon:postSchema
   *
   * @usage search_api_pantheon:postSchema {$server_id}
   *   Post the latest schema to the given Server. Default server ID = pantheon_solr8.
   *
   * @command search_api_pantheon:postSchema ${$server_id}
   * @aliases sapps
   */
  public function postSchema(string $server_id = 'pantheon_solr8')
  {
    try {
      $schema_poster = \Drupal::service('search_api_pantheon.schema_poster');
      $schema_poster->postSchema($server_id);
    } catch (\Exception $e) {
      $this->logger()->error((string)$e);
    }
  }

  /**
   * search_api_pantheon:getSchemaFiles
   *
   * @usage search_api_pantheon:getSchemaFiles
   *   get the latest schema for the default pantheon solr server
   *
   * @command search_api_pantheon:getSchemaFiles
   * @aliases sapgsf
   */
  public function outputFiles(array $files)
  {
    $files = $this->getSolrFiles();
    $temp_dir = ($_SERVER['TMPDIR'] ?? getcwd()) . DIRECTORY_SEPARATOR . uniqid('search_api_pantheon-');
    $this->output()->writeln("outputingg files to $temp_dir");
    $zip_archive = new \ZipArchive();
    $zip_archive->open($temp_dir . '.zip', \ZipArchive::CREATE);
    foreach ($files as $filename => $file_contents) {
      $zip_archive->addFromString($filename, $file_contents);
    }
    $zip_archive->close();
    return $temp_dir;
  }

  /**
   * search_api_pantheon:test
   *
   * @usage search_api_pantheon-test
   *   connect to the solr8 server
   *
   * @command search_api_pantheon:test
   * @aliases sapt
   */
  public function testInstall()
  {
    $this->logger()->notice('Index SCHEME Value: {var}', [
      'var' => isset($_SERVER['PANTHEON_INDEX_SCHEME'])
        ? getenv('PANTHEON_INDEX_SCHEME')
        : 'https',
    ]);
    $this->logger()->notice('Index HOST Value:   {var}', [
      'var' => getenv('PANTHEON_INDEX_HOST'),
    ]);
    $this->logger()->notice('Index PORT Value:   {var}', [
      'var' => getenv('PANTHEON_INDEX_PORT'),
    ]);
    $this->logger()->notice('Index CORE Value:   {var}', [
      'var' => Cores::getMyCoreName(),
    ]);
    $this->logger()->notice('Index PATH Value:   {var}', [
      'var' => isset($_SERVER['PANTHEON_INDEX_PATH'])
        ? getenv('PANTHEON_INDEX_PATH')
        : '/',
    ]);
    $this->logger()->notice('Testing bare Connection...');
    $response = $this->pingSolrHost();
    $this->logger()->notice('Ping Received Response? {var}', [
      'var' => $response instanceof ResultInterface ? '✅' : '❌',
    ]);
    $this->logger()->notice('Response http status == 200? {var}', [
      'var' => $response->getResponse()->getStatusCode() === 200 ? '✅' : '❌',
    ]);
    $this->logger()->notice('Response status == 0 (no issue)? {var}', [
      'var' => $response->getStatus() === 0 ? '✅' : '❌',
    ]);
    $this->logger()->notice('Drupal Integration...');
    $manager = \Drupal::getContainer()->get(
      'plugin.manager.search_api_solr.connector'
    );
    $connectors = array_keys($manager->getDefinitions() ?? []);
    $this->logger()->notice('Pantheon Connector Plugin Exists? {var}', [
      'var' => in_array('pantheon', $connectors) ? '✅' : '❌',
    ]);
    $connectorPlugin = $manager->createInstance('pantheon');
    $this->logger()->notice('Connector Plugin Instance created {var}', [
      'var' => $connectorPlugin instanceof SolrConnectorInterface ? '✅' : '❌',
    ]);
    $this->logger()->notice('Using connector plugin to...');
    $connectorPlugin->setLogger($this->logger);
    $connectorPlugin->getEndpoint('pantheon')->setLogger($this->logger);
    $info = $connectorPlugin->getServerInfo();
    $this->logger()->notice('Solr Server Version {var}', [
      'var' => $info['lucene']['solr-spec-version'] ?? '❌',
    ]);
    $this->logger()->notice(
      "If there's an issue with the connection, it would have shown up here."
    );
  }



  protected function pingSolrHost()
  {
    $config = [
      'endpoint' => [],
    ];
    try {
      $cert = $_SERVER['HOME'] . '/certs/binding.pem';
      $guzzleConfig = [
        'http_errors' => true,
        'debug' => $this->output()->isVerbose(),
        'verify' => false,
      ];
      if (is_file($cert)) {
        $guzzleConfig['cert'] = $cert;
      }
      $guzzleClient = new \RicardoFiorani\GuzzlePsr18Adapter\Client(
        $guzzleConfig
      );
      $adapter = new Psr18Adapter(
        $guzzleClient,
        new RequestFactory(),
        new StreamFactory()
      );

      $solr = new \Solarium\Client(
        $adapter,
        new \Symfony\Component\EventDispatcher\EventDispatcher(),
        $config
      );
      $endpoint = new Endpoint([
                                 'collection' => null,
                                 'leader' => false,
                                 'timeout' => 5,
                                 'solr_version' => '8',
                                 'http_method' => 'AUTO',
                                 'commit_within' => 1000,
                                 'jmx' => false,
                                 'solr_install_dir' => '',
                                 'skip_schema_check' => false,
                               ]);
      $endpoint->setKey('pantheon');
      $solr->addEndpoint($endpoint);
      $ping = $solr->createPing();
      return $solr->ping($ping);
    } catch (\Exception $e) {
      exit($e->getMessage());
    } catch (\Throwable $t) {
      exit($t->getMessage());
    }
  }

}
