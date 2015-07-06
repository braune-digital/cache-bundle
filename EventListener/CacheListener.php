<?php

namespace BrauneDigital\CacheBundle\EventListener;

use Application\AppBundle\Entity\Offer;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Application\AppBundle\Entity\OfferTranslation;
use Doctrine\ORM\Event\PostFlushEventArgs;
use FOS\HttpCacheBundle\Configuration\InvalidatePath;
use Symfony\Component\Debug\Exception\ContextErrorException;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\PropertyAccess\Exception\UnexpectedTypeException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;


class CacheListener extends ContainerAware {

	protected $container;
	protected $cacheManager;
	protected $router;
	protected $entity;
	protected $locales;
	protected $changeset;
	protected $accessor;
	protected $newPath = array();
	protected $invalidateEntities = array();

	/**
	 * @param ContainerInterface $container
	 */
	public function __construct(ContainerInterface $container)
	{
		$this->container = $container;
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function prePersist(LifecycleEventArgs $args) {
		return;
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function postPersist(LifecycleEventArgs $args) {
		$this->postUpdate($args);
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function preUpdate(LifecycleEventArgs $args) {
		$this->invalidateEntities = array();
		$this->refreshAndInvalidate($args, 'invalidate');
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	public function postUpdate(LifecycleEventArgs $args) {
		$this->refreshAndInvalidate($args, 'refresh');
	}


	private function refreshAndInvalidate(LifecycleEventArgs $args, $type = 'refresh') {
		if (get_class($args->getEntity()) == 'BrauneDigital\RedirectBundle\Entity\Redirect') {
			return;
		}

		$this->prepare($args);
		$cacheConfiguration = $this->container->getParameter('braune_digital_cache');

		/**
		 * Are there any changes?
		 */
		if (count($this->changeset) > 0) {

			/**
			 * Iterate over configured entities
			 */
			foreach ($cacheConfiguration['entities'] as $entityIndex => $entityConfig) {

				/**
				 * CHeck for translation
				 */
				$entityIsTranslation = false;
				if ($entityConfig['check_translation']) {
					$entityIsTranslation = (get_class($this->entity) == $entityConfig['entity'] . 'Translation') ? true : false;
				}

				if (get_class($this->entity) == $entityConfig['entity'] or $entityIsTranslation) {
					if ($entityIsTranslation) {
						$translationEntity = $this->entity;
						$this->entity = $this->entity->getTranslatable();
					}

					/**
					 * Iterate over configured routes
					 */
					foreach ($entityConfig['routes'] as $route => $routeConfiguration) {

						/**
						 * Check for a specific or all fields
						 */
						$listenToAllFields = false;
						if (isset($routeConfiguration['listenTo'])) {
							$routeConfiguration['listenTo'] = explode('|', $routeConfiguration['listenTo']);
						} else {
							$listenToAllFields = true;
						}

						if ($listenToAllFields or $this->fieldHasChanched($routeConfiguration['listenTo'])) {

							/**
							 * Get the original entity to update routes with old data.
							 */
							$refreshEntities = array();


							/**
							 * Check if there are routes from old properties that should be invalidated
							 */
							if ($routeConfiguration['refresh_with_original_params'] || $routeConfiguration['invalidate_with_original_params']) {
								if ($entityIsTranslation) {
									$changeset = $args->getEntityManager()->getUnitOfWork()->getEntityChangeSet($translationEntity);
								} else {
									$changeset = $args->getEntityManager()->getUnitOfWork()->getEntityChangeSet($this->entity);
								}

								$originalDataEntity = unserialize(serialize($this->entity));
								foreach ($changeset as $field => $values) {
									try {
										call_user_func(array($originalDataEntity, 'set' . ucfirst($field)), $values[0]);
									} catch (ContextErrorException $e) {}
								}

								switch ($type) {
									case 'refresh':
										$refreshEntities[] = $this->entity;
										if ($routeConfiguration['refresh_with_original_params']) {
											$refreshEntities[] = $originalDataEntity;
										}
										if (count($refreshEntities) > 0) {
											$this->process($refreshEntities, $cacheConfiguration, $routeConfiguration, $entityIndex, $route, 'refresh');
										}
										var_dump(count($this->invalidateEntities));
										if (count($this->invalidateEntities) > 0) {
											$this->process($this->invalidateEntities, $cacheConfiguration, $routeConfiguration, $entityIndex, $route, 'invalidate');
										}
										break;

									case 'invalidate':
										if ($routeConfiguration['invalidate_with_original_params']) {
											$this->invalidateEntities[] = $originalDataEntity;
										}
										break;
								}
							}
						}
					}
				}
			}
		}

	}

	/**
	 * @param array $entities
	 * @param $entityIndex
	 * @param $route
	 * @param string $type
	 */
	private function process(array $entities, $cacheConfiguration, $routeConfiguration, $entityIndex, $route, $type = 'refresh') {
		/**
		 * Iterate over the entities
		 */
		foreach ($entities as $entity) {

			/**
			 * Use accessor component to get entity properties
			 */
			try {
				if (isset($routeConfiguration['mapping']) && count($routeConfiguration['mapping']) > 0) {
					foreach ($routeConfiguration['mapping'] as $param => $accessorPath) {
						$cacheConfiguration['entities'][$entityIndex]['routes'][$route]['mapping'][$param] = $this->accessor->getValue($entity, $accessorPath);
					}
				}

				$routeConfiguration = $cacheConfiguration['entities'][$entityIndex]['routes'][$route];

				/**
				 * Iterate over locales
				 */
				foreach ($this->locales as $locale) {
					/**
					 * Merge mapping with locale
					 */
					$params = array('_locale' => $locale);
					if (isset($routeConfiguration['mapping']) && count($routeConfiguration['mapping']) > 0) {
						$params = array_merge(array(
							'_locale' => $locale
						), $routeConfiguration['mapping']);
					}

					/**
					 * Generate and refresh/invalidate routes
					 */
					$path = $this->router->generate($route, $params, true);
					$pathRelative = $this->router->generate($route, $params, false);


					switch($type) {
						case 'refresh':
							$this->newPath[$locale] = $pathRelative;
							$this->cacheManager->refreshPath($path);
							break;
						case 'invalidate':
									var_dump($this->newPath);
							$this->cacheManager->invalidatePath($path);
							if ($this->newPath) {
								try {
									$redirectManager = $this->container->get('braunedigital.redirect.manager');
									$redirectManager->create($pathRelative, $this->newPath[$locale], Response::HTTP_MOVED_PERMANENTLY);
								} catch(\Exception $e) {}
							}

							break;
					}

				}
			} catch (UnexpectedTypeException $e) {

			}
		}

	}


	/**
	 * @param array $fields
	 * @return bool
	 */
	private function fieldHasChanched(array $fields) {
		foreach ($fields as $field) {
			if (in_array($field, $this->changeset)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param LifecycleEventArgs $args
	 */
	private function prepare(LifecycleEventArgs $args) {

		$this->cacheManager = $this->container->get('fos_http_cache.cache_manager');
		$this->router = $this->container->get('router');
		$this->entity = $args->getEntity();
		$this->accessor = PropertyAccess::createPropertyAccessorBuilder()->enableMagicCall()->getPropertyAccessor();
		$this->changeset = array_keys($args->getEntityManager()->getUnitOfWork()->getEntityChangeSet($this->entity));
		$this->locales = $this->container->getParameter('locales');

	}

}