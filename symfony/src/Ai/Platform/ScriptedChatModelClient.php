<?php

declare(strict_types=1);

namespace App\Ai\Platform;

use App\Ai\Question\AskUserQuestion;
use App\Ai\Watch\WatchSubject;
use App\Ai\Watch\WatchTool;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Répond comme un fournisseur « chat completions », sans réseau ni clé d'API.
 *
 * La démo n'a pas besoin d'un vrai modèle pour montrer ce qu'elle montre : le journal, les
 * activités, la garde et la reprise après signal. Un vrai fournisseur se branche en remplaçant ce
 * service par le `ModelClientInterface` d'un bridge `symfony/ai-*-platform`.
 *
 * La réponse est une fonction pure de la conversation reçue — donc déterministe, donc rejouable.
 */
final class ScriptedChatModelClient implements ModelClientInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $messages = $payload['messages'] ?? [];

        if ($this->isCompactionRequest($messages)) {
            return new InMemoryRawResult($this->text($this->digest($messages)));
        }

        $lastUser = '';
        $answeredSinceUser = 0;

        foreach ($messages as $message) {
            if ('user' === ($message['role'] ?? null)) {
                $lastUser = mb_strtolower((string) $message['content']);
                $answeredSinceUser = 0;
            } elseif ('tool' === ($message['role'] ?? null)) {
                ++$answeredSinceUser;
            }
        }

        // Un seul tour d'outil par message : au second passage, on répond.
        if ($answeredSinceUser > 0) {
            return new InMemoryRawResult($this->text(
                $this->summarise($messages),
                'L’outil a répondu ; je rends son relevé tel quel plutôt que de le paraphraser.',
            ));
        }

        // Une demande vague : le modèle ne devine pas, il demande. C'est ce que fait un vrai
        // modèle quand la consigne laisse plusieurs suites également raisonnables.
        if (str_contains($lastUser, 'import')) {
            return new InMemoryRawResult($this->toolCall($messages, AskUserQuestion::TOOL, [
                'question' => 'Comment veux-tu lancer cet import ?',
                'header' => 'Mode d’import',
                'options' => [
                    ['label' => 'Par lot', 'description' => 'Tout d’un coup, plus rapide, bloque le catalogue'],
                    ['label' => 'Au fil de l’eau', 'description' => 'Plus lent, le catalogue reste servi'],
                    ['label' => 'Simulation', 'description' => 'Rien n’est écrit, on regarde ce qui changerait'],
                ],
            ]));
        }

        if (str_contains($lastUser, 'surveille') || str_contains($lastUser, 'préviens')) {
            return new InMemoryRawResult($this->toolCall($messages, WatchTool::TOOL, [
                // Le sujet est ce qui rend la veille joignable : `app:agent:evenement
                // commande.expediee` la réveille, un texte libre ne l'aurait jamais réveillée.
                'sujet' => WatchSubject::CommandeExpediee->value,
                'observation' => 'La livraison du fournisseur arrive à l’entrepôt',
                'intention' => 'Enregistrer une note de réception et prévenir l’équipe',
                'deadlineSeconds' => 900,
            ]));
        }

        // Le levier explicite : un vrai modèle décide seul de demander, un modèle scripté a besoin
        // qu'on le lui dise. « demande-moi… » sert à voir le questionnaire à volonté, et « choix
        // multiple » à voir l'autre forme.
        if (str_contains($lastUser, 'demande') || str_contains($lastUser, 'question')) {
            return new InMemoryRawResult($this->toolCall($messages, AskUserQuestion::TOOL, [
                'question' => 'Sur quoi veux-tu que je tranche ?',
                'header' => 'À toi de voir',
                'multiSelect' => str_contains($lastUser, 'multiple'),
                'options' => [
                    ['label' => 'La météo', 'description' => 'Je consulte, personne n’a rien à valider'],
                    ['label' => 'Une note', 'description' => 'J’écris dans le dossier courant'],
                    ['label' => 'Un courriel', 'description' => 'Effet externe : la garde demandera ton accord'],
                ],
            ]));
        }

        if (str_contains($lastUser, 'mail') || str_contains($lastUser, 'courriel')) {
            return new InMemoryRawResult($this->toolCall($messages, 'send_email', [
                'to' => 'equipe@example.test',
                'body' => 'Compte rendu demandé depuis le chat durable.',
            ]));
        }

        if (str_contains($lastUser, 'note')) {
            return new InMemoryRawResult($this->toolCall($messages, 'save_note', ['text' => $lastUser]));
        }

        foreach (['paris', 'lyon', 'marseille'] as $city) {
            if (str_contains($lastUser, $city)) {
                return new InMemoryRawResult($this->toolCall($messages, 'weather', ['city' => ucfirst($city)]));
            }
        }

        return new InMemoryRawResult($this->text(
            'Je sais consulter la météo d’une ville, enregistrer une note, ou envoyer un courriel. Lequel ?'
        ));
    }

    /**
     * La compaction arrive par la même porte que le reste — c'est sa consigne système qui la
     * distingue, comme elle le ferait chez un vrai fournisseur.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function isCompactionRequest(array $messages): bool
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? null)
                && str_contains((string) $message['content'], 'Résume-la')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un résumé pour de faux, mais qui dit vrai : ce qui a été demandé, et où la conversation en
     * était restée. Fonction pure de la conversation reçue, donc rejouable.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function digest(array $messages): string
    {
        $asked = [];
        $lastAnswer = '';
        foreach ($messages as $message) {
            $role = $message['role'] ?? null;
            if ('user' === $role) {
                $asked[] = trim((string) $message['content']);
            } elseif ('assistant' === $role && null !== ($message['content'] ?? null)) {
                $lastAnswer = trim((string) $message['content']);
            }
        }

        if ([] === $asked) {
            return 'La conversation précédente n’a pas dépassé les présentations.';
        }

        return \sprintf(
            'La personne avait demandé : %s. Dernière réponse donnée : « %s ». Rien n’est resté en attente.',
            implode(', ', array_map(static fn (string $q): string => '« ' . $q . ' »', $asked)),
            '' === $lastAnswer ? 'aucune' : $lastAnswer,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function summarise(array $messages): string
    {
        $last = '';
        foreach ($messages as $message) {
            if ('tool' === ($message['role'] ?? null)) {
                $last = (string) $message['content'];
            }
        }

        return '' === $last ? 'C’est fait.' : $last;
    }

    /**
     * L'identifiant d'appel dérive du rang du tour : deux constructions de la même conversation
     * donnent le même identifiant, sinon le rejeu divergerait.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCall(array $messages, string $tool, array $arguments): array
    {
        return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
            'id' => \sprintf('call_%d', \count($messages)),
            'type' => 'function',
            'function' => ['name' => $tool, 'arguments' => json_encode($arguments, \JSON_UNESCAPED_UNICODE)],
        ]]], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * La forme d'un tour raisonné **chez Mistral** : une liste de morceaux `thinking` et `text`,
     * pas un `reasoning_content` à côté. Le contrat générique envoie ce dernier, et Mistral le
     * refuse par un 422 « Extra inputs are not permitted » ({@see \Symfony\AI\Platform\Bridge\Mistral\Contract\AssistantMessageNormalizer}).
     *
     * Sans raisonnement, on garde la chaîne simple qu'attendent tous les autres modèles : le
     * convertisseur du pont ne descend dans les morceaux que si `content` est un tableau.
     *
     * @return array<string, mixed>
     */
    private function text(string $text, string $reasoning = ''): array
    {
        return ['choices' => [['message' => [
            'content' => '' === $reasoning ? $text : [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $reasoning]]],
                ['type' => 'text', 'text' => $text],
            ],
        ], 'finish_reason' => 'stop']]];
    }
}
