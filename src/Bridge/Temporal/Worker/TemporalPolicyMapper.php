<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\WorkflowTimeouts;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Temporal\Api\Common\V1\SearchAttributes as TemporalSearchAttributes;
use Temporal\Api\Enums\V1\ParentClosePolicy as TemporalParentClosePolicy;
use Temporal\Api\Enums\V1\WorkflowIdReusePolicy as TemporalIdReusePolicy;

/**
 * Conversions des options durables vers leurs équivalents protobuf.
 *
 * Partagées par le buffer de commandes (workflows enfants) et le client (démarrage racine) :
 * racine et enfant décrivent les mêmes réglages, ils ne doivent pas les traduire différemment.
 */
final class TemporalPolicyMapper
{
    private function __construct()
    {
    }

    public static function parentClosePolicy(mixed $policy): int
    {
        $value = $policy instanceof ParentClosePolicy
            ? $policy
            : ParentClosePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            ParentClosePolicy::Abandon => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_ABANDON,
            ParentClosePolicy::RequestCancel => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_REQUEST_CANCEL,
            default => TemporalParentClosePolicy::PARENT_CLOSE_POLICY_TERMINATE,
        };
    }

    public static function idReusePolicy(mixed $policy): int
    {
        $value = $policy instanceof WorkflowIdReusePolicy
            ? $policy
            : WorkflowIdReusePolicy::tryFrom((string) (\is_scalar($policy) ? $policy : ''));

        return match ($value) {
            WorkflowIdReusePolicy::AllowDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE,
            WorkflowIdReusePolicy::RejectDuplicate => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_REJECT_DUPLICATE,
            default => TemporalIdReusePolicy::WORKFLOW_ID_REUSE_POLICY_ALLOW_DUPLICATE_FAILED_ONLY,
        };
    }

    /**
     * Pose les bornes temporelles sur n'importe quel message qui les accepte — requête de
     * démarrage, commande d'enfant, commande de continue-as-new : ils exposent les mêmes
     * setters, et ne doivent pas traduire les mêmes options différemment.
     *
     * @param object $target message protobuf exposant setWorkflowExecutionTimeout /
     *                       setWorkflowRunTimeout / setWorkflowTaskTimeout
     */
    public static function applyWorkflowTimeouts(WorkflowTimeouts $timeouts, object $target): void
    {
        foreach ([
            'setWorkflowExecutionTimeout' => $timeouts->execution,
            'setWorkflowRunTimeout' => $timeouts->run,
            'setWorkflowTaskTimeout' => $timeouts->task,
        ] as $setter => $bound) {
            if (null !== $bound && method_exists($target, $setter)) {
                $target->{$setter}(self::duration($bound->toSeconds()));
            }
        }
    }

    /**
     * Pose les attributs de recherche sur un message qui les accepte.
     *
     * Le type accompagne chaque valeur dans les métadonnées de la charge utile. Le serveur
     * applique en réalité celui de son registre — mais l'annoncer rend l'intention lisible pour
     * qui inspecte l'historique.
     */
    public static function applySearchAttributes(SearchAttributes $attributes, object $target): void
    {
        if ($attributes->isEmpty() || !method_exists($target, 'setSearchAttributes')) {
            return;
        }

        $message = new TemporalSearchAttributes();
        $fields = $message->getIndexedFields();
        foreach ($attributes->toValues() as $name => $value) {
            // Le type accompagne la valeur dans les métadonnées de la charge utile.
            $fields[$name] = JsonPlainPayload::encodeWithMetadata($value, [
                'type' => (string) $attributes->typeOf($name)?->value,
            ]);
        }
        $message->setIndexedFields($fields);

        $target->setSearchAttributes($message);
    }

    public static function duration(float $seconds): Duration
    {
        $duration = new Duration();
        $whole = (int) floor($seconds);
        $nanos = (int) round(($seconds - $whole) * 1_000_000_000);
        if ($nanos >= 1_000_000_000) {
            ++$whole;
            $nanos -= 1_000_000_000;
        }
        $duration->setSeconds($whole);
        $duration->setNanos($nanos);

        return $duration;
    }
}
