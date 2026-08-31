<?php

declare(strict_types=1);

namespace App\Ai\Guard;

/**
 * Ce qu'est devenue une demande de validation. Trois issues, pas un booléen : « refusé » et
 * « jamais répondu » se ressemblent dans leurs effets — l'outil ne part pas — mais pas du tout
 * dans ce qu'elles disent. L'un est une décision, l'autre est une absence de décision, et un
 * journal qui les confond ne permet plus de savoir si quelqu'un a regardé.
 */
enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case Refused = 'refused';
    case Expired = 'expired';

    /**
     * Ce que le modèle lit à la place du résultat de l'outil.
     */
    public function message(): string
    {
        return match ($this) {
            self::Approved => '',
            self::Refused => 'Refusé par l’utilisateur.',
            self::Expired => 'Refusé : aucune validation reçue avant l’échéance.',
        };
    }
}
