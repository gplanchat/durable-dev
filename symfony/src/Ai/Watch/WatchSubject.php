<?php

declare(strict_types=1);

namespace App\Ai\Watch;

/**
 * Ce qu'une veille peut guetter — le vocabulaire fermé des événements que l'application sait
 * publier.
 *
 * **C'est l'acte de design, comme {@see \App\Ai\Guard\ToolEffect} l'est pour la garde.** Une veille
 * décrite en texte libre est une veille que rien ne pourra jamais lever : l'agent écrirait « quand
 * la livraison arrive », l'événement métier dirait `commande.expediee`, et personne ne ferait le
 * rapprochement — sans erreur, sans trace, l'agent dormirait jusqu'à son échéance. Même classe de
 * panne silencieuse qu'un bloc de raisonnement qu'on laisse tomber.
 *
 * D'où un enum plutôt qu'une chaîne : la liste part au modèle dans le schéma de l'outil, et un
 * sujet inconnu est **refusé visiblement** ({@see Watch::fromArguments()}) au lieu de produire une
 * veille morte.
 *
 * Ajouter un sujet, c'est ajouter un cas ici et publier l'événement correspondant. Rien d'autre.
 */
enum WatchSubject: string
{
    case CommandeExpediee = 'commande.expediee';
    case PaiementRecu = 'paiement.recu';
    case StockReappro = 'stock.reapprovisionne';
    case FournisseurRepondu = 'fournisseur.a_repondu';
    case ImportTermine = 'import.termine';

    /**
     * Ce que le modèle lit dans le schéma : sans ça il choisirait au hasard dans une liste de
     * chaînes opaques.
     */
    public function describe(): string
    {
        return match ($this) {
            self::CommandeExpediee => 'une commande a quitté l’entrepôt',
            self::PaiementRecu => 'un paiement a été encaissé',
            self::StockReappro => 'un produit a été réapprovisionné',
            self::FournisseurRepondu => 'un fournisseur a répondu à une demande',
            self::ImportTermine => 'un import de catalogue s’est terminé',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
