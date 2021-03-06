<?php

namespace MediaMonks\Doctrine\Transformable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Gedmo\Mapping\Event\AdapterInterface;
use Gedmo\Mapping\MappedEventSubscriber;
use MediaMonks\Doctrine\Transformable\Transformer\TransformerInterface;
use MediaMonks\Doctrine\Transformable\Transformer\TransformerPool;

/**
 * @author Robert Slootjes <robert@mediamonks.com>
 * @author Bas Bloembergen <basb@mediamonks.com>
 */
class TransformableSubscriber extends MappedEventSubscriber
{
    const TRANSFORMABLE = 'transformable';

    const FUNCTION_TRANSFORM = 'transform';
    const FUNCTION_REVERSE_TRANSFORM = 'reverseTransform';

    const TYPE_TRANSFORMED = 'transformed';
    const TYPE_PLAIN = 'plain';

    /**
     * @var TransformerPool
     */
    protected $transformerPool;

    /**
     * @var array
     */
    protected $entityFieldValues = [];

    /**
     * TransformableListener constructor.
     * @param TransformerPool $transformerPool
     */
    public function __construct(TransformerPool $transformerPool)
    {
        $this->transformerPool = $transformerPool;
        parent::__construct();
    }

    /**
     * @return array
     */
    public function getSubscribedEvents()
    {
        return [
            Events::loadClassMetadata,
            Events::onFlush,
            Events::postPersist,
            Events::postLoad,
            Events::postUpdate,
        ];
    }

    /**
     * @param EventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * @param EventArgs $args
     */
    public function onFlush(EventArgs $args)
    {
        $this->transform($args);
    }

    /**
     * @param EventArgs $args
     */
    public function postPersist(EventArgs $args)
    {
        $this->reverseTransform($args);
    }

    /**
     * @param EventArgs $args
     */
    public function postLoad(EventArgs $args)
    {
        $this->reverseTransform($args);
    }

    /**
     * @param EventArgs $args
     */
    public function postUpdate(EventArgs $args)
    {
        $this->reverseTransform($args);
    }

    /**
     * @param EventArgs $args
     */
    protected function transform(EventArgs $args)
    {
        $ea  = $this->getEventAdapter($args);
        $om  = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();

        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $this->handle($ea, $om, $uow, $object, self::FUNCTION_TRANSFORM);
        }

        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $this->handle($ea, $om, $uow, $object, self::FUNCTION_TRANSFORM);
        }
    }

    /**
     * @param EventArgs $args
     */
    protected function reverseTransform(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();

        $this->handle($ea, $om, $om->getUnitOfWork(), $ea->getObject(), self::FUNCTION_REVERSE_TRANSFORM);
    }

    /**
     * @param AdapterInterface $ea
     * @param ObjectManager $om
     * @param UnitOfWork $uow
     * @param object $entity
     * @param string $method
     */
    protected function handle(AdapterInterface $ea, ObjectManager $om, UnitOfWork $uow, $entity, $method)
    {
        /**
         * @var \Doctrine\ORM\EntityManager $om
         */
        $meta = $om->getClassMetadata(get_class($entity));
        $config = $this->getConfiguration($om, $meta->name);

        if (isset($config[self::TRANSFORMABLE]) && $config[self::TRANSFORMABLE]) {
            foreach ($config[self::TRANSFORMABLE] as $column) {
                $this->handleField($entity, $method, $column, $meta);
            }
            $ea->recomputeSingleObjectChangeSet($uow, $meta, $entity);
        }
    }

    /**
     * @param $entity
     * @param string $method
     * @param array $column
     * @param ClassMetadata $meta
     */
    protected function handleField($entity, $method, array $column, $meta)
    {
        $field = $column['field'];
        $oid   = spl_object_hash($entity);

        $reflProp = $meta->getReflectionProperty($field);
        $oldValue = $reflProp->getValue($entity);
        $newValue = $this->getNewValue($oid, $field, $column['name'], $method, $oldValue);
        $reflProp->setValue($entity, $newValue);

        if ($method === self::FUNCTION_REVERSE_TRANSFORM) {
            $this->storeOriginalFieldData($oid, $field, $oldValue, $newValue);
        }
    }

    /**
     * @param string $oid
     * @param string $field
     * @param string $transformerName
     * @param string $method
     * @param mixed $value
     * @return mixed
     */
    protected function getNewValue($oid, $field, $transformerName, $method, $value)
    {
        if ($method === self::FUNCTION_TRANSFORM
            && $this->getEntityFieldValue($oid, $field, self::TYPE_PLAIN) === $value
        ) {
            return $this->getEntityFieldValue($oid, $field, self::TYPE_TRANSFORMED);
        }
        return $this->performTransformerOperation($transformerName, $method, $value);
    }

    /**
     * @param string $transformerName
     * @param string $method
     * @param mixed $oldValue
     * @return mixed
     */
    protected function performTransformerOperation($transformerName, $method, $oldValue)
    {
        return $this->getTransformer($transformerName)->$method($oldValue);
    }

    /**
     * @param string $oid
     * @param string $field
     * @param string $type
     * @return mixed|null
     */
    protected function getEntityFieldValue($oid, $field, $type)
    {
        if(!isset($this->entityFieldValues[$oid][$field])) {
            return null;
        }
        return $this->entityFieldValues[$oid][$field][$type];
    }

    /**
     * @param string $oid
     * @param string $field
     * @param mixed $transformed
     * @param mixed $plain
     */
    protected function storeOriginalFieldData($oid, $field, $transformed, $plain)
    {
        $this->entityFieldValues[$oid][$field] = [
            self::TYPE_TRANSFORMED => $transformed,
            self::TYPE_PLAIN       => $plain
        ];
    }

    /**
     * @param string $name
     * @return TransformerInterface
     */
    protected function getTransformer($name)
    {
        return $this->transformerPool->get($name);
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
