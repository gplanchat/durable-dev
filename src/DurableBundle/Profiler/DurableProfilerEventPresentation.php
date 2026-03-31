<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;

/**
 * Libellés « métier » pour le profiler Web (titres + sous-titres + catégorie d’affichage).
 *
 * Les noms de classes PHP restent en payload ; ici on privilégie une lecture humaine en français.
 */
final class DurableProfilerEventPresentation
{
    /**
     * @param array<string, mixed> $entry entrée {@see DurableExecutionTrace}
     *
     * @return array{title: string, subtitle: string, category: string}
     */
    /**
     * Libellé pour le tableau « Ordre chronologique » et les détails dispatch.
     *
     * Les reprises {@see \Gplanchat\Durable\Transport\WorkflowRunMessage::isResume} n’incluent pas le type de
     * workflow dans le message (vide) : le handler le lit depuis les métadonnées.
     *
     * @param array<string, mixed> $entry entrée {@see DurableExecutionTrace}
     */
    public static function dispatchTimelineLabel(array $entry): string
    {
        $wt = trim((string) ($entry['workflowType'] ?? ''));
        $isResume = (bool) ($entry['isResume'] ?? false);
        $tn = (string) ($entry['transportNames'] ?? '');

        $parts = [];
        if ($isResume) {
            $parts[] = 'Reprise Messenger (WorkflowRunMessage)';
            $parts[] = 'sans type dans le message — résolu au handler depuis les métadonnées';
        } elseif ('' !== $wt) {
            $parts[] = 'Nouveau run « '.$wt.' » (WorkflowRunMessage)';
        } else {
            $parts[] = 'WorkflowRunMessage (type non renseigné dans le message)';
        }
        if ('' !== $tn) {
            $parts[] = 'transports : '.$tn;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $entry entrée {@see DurableExecutionTrace}
     *
     * @return array{title: string, subtitle: string, category: string}
     */
    public static function fromProcessTrace(array $entry): array
    {
        $kind = (string) ($entry['kind'] ?? '');

        return match ($kind) {
            'dispatch' => [
                'title' => (bool) ($entry['isResume'] ?? false)
                    ? 'Reprise Messenger'
                    : ('' !== trim((string) ($entry['workflowType'] ?? ''))
                        ? 'Nouveau run Messenger'
                        : 'Message WorkflowRunMessage'),
                'subtitle' => self::dispatchTimelineLabel($entry),
                'category' => 'messenger',
            ],
            'workflow' => [
                'title' => !empty($entry['isResume']) ? 'Reprise du workflow' : 'Démarrage du workflow',
                'subtitle' => (string) ($entry['workflowType'] ?? ''),
                'category' => 'workflow',
            ],
            'activity' => [
                'title' => 'Exécution d’activité',
                'subtitle' => \sprintf(
                    '%s · id %s%s',
                    $entry['activityName'] ?? '?',
                    $entry['activityId'] ?? '?',
                    empty($entry['success']) ? ' · échec' : '',
                ),
                'category' => 'activity',
            ],
            default => [
                'title' => $kind !== '' ? $kind : 'Événement',
                'subtitle' => '',
                'category' => 'default',
            ],
        };
    }

    /**
     * @return array{title: string, subtitle: string, category: string, technical: string}
     */
    public static function fromStoreEvent(Event $event): array
    {
        $technical = (new \ReflectionClass($event))->getShortName();

        if ($event instanceof ExecutionStarted) {
            return [
                'title' => 'Démarrage de l’exécution',
                'subtitle' => 'Le moteur rejoue le workflow à partir du journal (event sourcing).',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ExecutionCompleted) {
            return [
                'title' => 'Fin de l’exécution',
                'subtitle' => 'Résultat final enregistré dans le journal.',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowExecutionFailed) {
            return [
                'title' => 'Échec du workflow',
                'subtitle' => 'Voir le payload pour le détail de l’erreur.',
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowContinuedAsNew) {
            return [
                'title' => 'Continue as new',
                'subtitle' => 'Nouvelle exécution : '.$event->nextWorkflowType(),
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityScheduled) {
            return [
                'title' => 'Activité mise en file',
                'subtitle' => $event->activityName().' · id '.$event->activityId(),
                'category' => 'scheduling',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityCompleted) {
            return [
                'title' => 'Activité terminée avec succès',
                'subtitle' => 'id '.$event->activityId(),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityFailed) {
            $msg = $event->failureMessage();
            if (\strlen($msg) > 120) {
                $msg = substr($msg, 0, 117).'…';
            }

            return [
                'title' => 'Activité en échec',
                'subtitle' => ($event->activityName() !== '' ? $event->activityName().' · ' : '').$msg,
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ActivityCancelled) {
            return [
                'title' => 'Activité annulée',
                'subtitle' => 'id '.$event->activityId().' · '.$event->reason(),
                'category' => 'activity',
                'technical' => $technical,
            ];
        }

        if ($event instanceof TimerScheduled) {
            return [
                'title' => 'Minuteur programmé',
                'subtitle' => 'id '.$event->timerId().($event->summary() !== '' ? ' · '.$event->summary() : ''),
                'category' => 'timer',
                'technical' => $technical,
            ];
        }

        if ($event instanceof TimerCompleted) {
            return [
                'title' => 'Minuteur déclenché',
                'subtitle' => 'id '.$event->timerId(),
                'category' => 'timer',
                'technical' => $technical,
            ];
        }

        if ($event instanceof SideEffectRecorded) {
            return [
                'title' => 'Effet de bord enregistré',
                'subtitle' => 'id '.$event->sideEffectId(),
                'category' => 'effect',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ChildWorkflowScheduled) {
            return [
                'title' => 'Workflow enfant planifié',
                'subtitle' => $event->childWorkflowType().' · enfant '.$event->childExecutionId(),
                'category' => 'child',
                'technical' => $technical,
            ];
        }

        if ($event instanceof ChildWorkflowCompleted) {
            return [
                'title' => 'Workflow enfant terminé',
                'subtitle' => 'enfant '.$event->childExecutionId(),
                'category' => 'child',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowSignalReceived) {
            return [
                'title' => 'Signal reçu',
                'subtitle' => '« '.$event->signalName().' »',
                'category' => 'signal',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowUpdateHandled) {
            return [
                'title' => 'Update traitée',
                'subtitle' => '« '.$event->updateName().' »',
                'category' => 'signal',
                'technical' => $technical,
            ];
        }

        if ($event instanceof WorkflowCancellationRequested) {
            return [
                'title' => 'Annulation demandée',
                'subtitle' => $event->reason(),
                'category' => 'lifecycle',
                'technical' => $technical,
            ];
        }

        return [
            'title' => $technical,
            'subtitle' => 'Événement du journal — voir le payload pour le détail.',
            'category' => 'default',
            'technical' => $technical,
        ];
    }
}
