<?php
namespace App\Doctrine\Event;

use App\Annotations\AuditLog\AuditIgnore;
use App\Entity;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * A hook into Doctrine's event listener to mark a station as
 * needing restart if one of its related entities is changed.
 */
class StationRequiresRestart implements EventSubscriber
{
    /** @var Reader */
    protected $reader;

    /**
     * @param Reader $reader
     */
    public function __construct(Reader $reader)
    {
        $this->reader = $reader;
    }

    public function getSubscribedEvents()
    {
        return [
            Events::onFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        $collections_to_check = [
            Entity\AuditLog::OPER_INSERT => $uow->getScheduledEntityInsertions(),
            Entity\AuditLog::OPER_UPDATE => $uow->getScheduledEntityUpdates(),
            Entity\AuditLog::OPER_DELETE => $uow->getScheduledEntityDeletions(),
        ];

        $stations_to_restart = [];
        foreach($collections_to_check as $change_type => $collection) {
            foreach ($collection as $entity) {
                if (($entity instanceof Entity\StationMount)
                    || ($entity instanceof Entity\StationRemote && $entity->isEditable())
                    || ($entity instanceof Entity\StationPlaylist && $entity->getStation()->useManualAutoDJ())) {
                    if (Entity\AuditLog::OPER_UPDATE === $change_type) {
                        $changes = $uow->getEntityChangeSet($entity);

                        // Look for the @AuditIgnore annotation on a property.
                        $class_reflection = new \ReflectionObject($entity);
                        foreach($changes as $change_field => $changeset)
                        {
                            $property = $class_reflection->getProperty($change_field);
                            $annotation = $this->reader->getPropertyAnnotation($property, AuditIgnore::class);

                            if (null !== $annotation) {
                                unset($changes[$change_field]);
                            }
                        }

                        if (empty($changes)) {
                            continue;
                        }
                    }

                    /** @var Entity\Station $station */
                    $station = $entity->getStation();
                    $stations_to_restart[$station->getId()] = $station;
                }
            }
        }

        if (count($stations_to_restart) > 0) {
            foreach($stations_to_restart as $station) {
                $station->setNeedsRestart(true);
                $em->persist($station);

                $station_meta = $em->getClassMetadata(Entity\Station::class);
                $uow->recomputeSingleEntityChangeSet($station_meta, $station);
            }
        }
    }
}
