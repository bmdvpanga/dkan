<?php

namespace Drupal\metastore;

use Drupal\metastore\Exception\CannotChangeUuidException;
use Drupal\metastore\Exception\InvalidJsonException;
use Drupal\metastore\Exception\MetastoreException;
use Drupal\metastore\Exception\MissingPayloadException;
use RootedData\RootedJsonData;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\common\JsonResponseTrait;

/**
 * Class Api.
 *
 * @todo Move docs stuff.
 */
class WebServiceApi implements ContainerInjectionInterface {
  use JsonResponseTrait;

  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * Metastore service.
   *
   * @var \Drupal\metastore\Service
   */
  private $service;

  /**
   * Inherited.
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new WebServiceApi(
      $container->get('request_stack'),
      $container->get('dkan.metastore.service')
    );
  }

  /**
   * Constructor.
   */
  public function __construct(RequestStack $requestStack, Service $service) {
    $this->requestStack = $requestStack;
    $this->service = $service;
  }

  /**
   * Get schemas.
   */
  public function getSchemas() {
    return $this->getResponse($this->service->getSchemas());
  }

  /**
   * Get schema.
   */
  public function getSchema(string $identifier) {
    try {
      return $this->getResponse($this->service->getSchema($identifier));
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 404);
    }
  }

  /**
   * Get all.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function getAll(string $schema_id) {
    $keepRefs = $this->wantObjectWithReferences();

    $output = array_map(function ($object) use ($keepRefs) {
      $modified_object = $keepRefs ? $this->swapReferences($object) : $this->service->removeReferences($object);
      return (object) $modified_object->get('$');
    }, $this->service->getAll($schema_id));

    $output = array_values($output);
    return $this->getResponse($output);
  }

  /**
   * Implements GET method.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function get(string $schema_id, string $identifier) {
    try {
      $object = $this->service->get($schema_id, $identifier);
      if ($this->wantObjectWithReferences()) {
        $object = $this->swapReferences($object);
      }
      else {
        $object = Service::removeReferences($object);
      }
      $object = (object) $object->get('$');
      return $this->getResponse($object);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 404);
    }
  }

  /**
   * Private.
   */
  private function wantObjectWithReferences() {
    $param = $this->requestStack->getCurrentRequest()
      ->get('show-reference-ids', FALSE);
    $param2 = $this->requestStack->getCurrentRequest()
      ->get('show_reference_ids', FALSE);
    if ($param === FALSE && $param2 === FALSE) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Private.
   */
  private function swapReferences(RootedJsonData $object): RootedJsonData {
    $no_schema_object = $this->service->getValidMetadataFactory()->get("$object", NULL);
    foreach ($no_schema_object->get('$') as $property => $value) {
      if (substr_count($property, "%Ref:") > 0) {
        $no_schema_object = $this->swapReference($property, $value, $no_schema_object);
      }
    }

    return Service::removeReferences($no_schema_object, "%Ref");
  }

  /**
   * Private.
   */
  private function swapReference($property, $value, RootedJsonData $object): RootedJsonData {
    $original = str_replace("%Ref:", "", $property);
    if ($object->__isset("$.{$original}")) {
      $object->set("$.{$original}", $value);
    }
    return $object;
  }

  /**
   * GET all resources associated with a dataset.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function getResources(string $schema_id, string $identifier) {
    try {
      return $this->getResponse($this->service->getResources($schema_id, $identifier));
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 404);
    }
  }

  /**
   * Implements POST method.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function post(string $schema_id) {
    try {
      $data = $this->getRequestContent();
      $this->checkIdentifier($data);
      $data = $this->service->getValidMetadataFactory()->get($data, $schema_id, ['method' => 'POST']);
      $identifier = $this->service->post($schema_id, $data);
      return $this->getResponse([
        "endpoint" => "{$this->getRequestUri()}/{$identifier}",
        "identifier" => $identifier,
      ], 201);
    }
    catch (MetastoreException $e) {
      return $this->getResponseFromException($e, $e->httpCode());
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }
  }

  /**
   * Publish the latest revision of a dataset.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function publish(string $schema_id, string $identifier) {
    try {
      $this->service->publish($schema_id, $identifier);
      return $this->getResponse((object) ["endpoint" => $this->getRequestUri(), "identifier" => $identifier]);
    }
    catch (MetastoreException $e) {
      return $this->getResponseFromException($e, $e->httpCode());
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }
  }

  /**
   * Implements PUT method.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function put($schema_id, string $identifier) {
    try {
      $data = $this->getRequestContent();
      $this->checkIdentifier($data, $identifier);
      $data = $this->service->getValidMetadataFactory()->get($data, $schema_id);
      $info = $this->service->put($schema_id, $identifier, $data);
      $code = ($info['new'] == TRUE) ? 201 : 200;
      return $this->getResponse(["endpoint" => $this->getRequestUri(), "identifier" => $info['identifier']], $code);
    }
    catch (MetastoreException $e) {
      return $this->getResponseFromException($e, $e->httpCode());
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }
  }

  /**
   * Implements PATCH method.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function patch($schema_id, $identifier) {

    try {
      $data = $this->getRequestContent();

      if (empty($data)) {
        throw new MissingPayloadException("Empty body");
      }
      $obj = json_decode($data);
      if (!$obj) {
        throw new InvalidJsonException("Invalid JSON");
      }
      $this->checkIdentifier($data, $identifier);

      $this->service->patch($schema_id, $identifier, $data);
      return $this->getResponse((object) ["endpoint" => $this->getRequestUri(), "identifier" => $identifier]);
    }
    catch (MetastoreException $e) {
      return $this->getResponseFromException($e, $e->httpCode());
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e, 400);
    }
  }

  /**
   * Implements DELETE method.
   *
   * @param string $schema_id
   *   The {schema_id} slug from the HTTP request.
   * @param string $identifier
   *   Identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The json response.
   */
  public function delete($schema_id, $identifier) {
    try {
      $this->service->delete($schema_id, $identifier);
      return $this->getResponse((object) ["message" => "Dataset {$identifier} has been deleted."]);
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e);
    }
  }

  /**
   * Provides the data catalog.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A json response, either the catalog or an exception.
   */
  public function getCatalog() : JsonResponse {
    try {
      return $this->getResponse($this->service->getCatalog());
    }
    catch (\Exception $e) {
      return $this->getResponseFromException($e);
    }
  }

  /**
   * Checks identifier.
   */
  private function checkIdentifier(string $data, $identifier = NULL) {
    $obj = json_decode($data);
    if (isset($identifier) && isset($obj->identifier) && $obj->identifier != $identifier) {
      throw new CannotChangeUuidException("Identifier cannot be modified");
    }
  }

  /**
   * Get the request's uri.
   *
   * @return string
   *   The uri.
   */
  private function getRequestUri(): string {
    return $this->requestStack->getCurrentRequest()->getRequestUri();
  }

  /**
   * Get the request's content.
   *
   * @return string
   *   The content.
   */
  private function getRequestContent(): string {
    return $this->requestStack->getCurrentRequest()->getContent();
  }

}
