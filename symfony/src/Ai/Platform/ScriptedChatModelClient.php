<?php

declare(strict_types=1);

namespace App\Ai\Platform;

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

        if (str_contains($lastUser, 'mail') || str_contains($lastUser, 'courriel')) {
            return new InMemoryRawResult($this->toolCall($messages, 'send_email', [
                'to' => 'equipe@example.test',
                'body' => 'Compte rendu demandé depuis le chat durable.',
            ], 'La demande parle de courriel. Effet externe : rien ne rattrape un envoi, je passe par l\'outil et j\'attends la garde.'));
        }

        if (str_contains($lastUser, 'note')) {
            return new InMemoryRawResult($this->toolCall($messages, 'save_note', ['text' => $lastUser], 'Une note à garder : écriture locale, réversible.'));
        }

        foreach (['paris', 'lyon', 'marseille'] as $city) {
            if (str_contains($lastUser, $city)) {
                return new InMemoryRawResult($this->toolCall($messages, 'weather', ['city' => ucfirst($city)], \sprintf('%s est nommée : une lecture suffit, aucun effet à compenser.', ucfirst($city))));
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
    private function toolCall(array $messages, string $tool, array $arguments, string $reasoning = ''): array
    {
        return ['choices' => [['message' => [
            'content' => null,
            'reasoning_content' => '' === $reasoning ? null : $reasoning,
            'tool_calls' => [[
                'id' => \sprintf('call_%d', \count($messages)),
                'type' => 'function',
                'function' => ['name' => $tool, 'arguments' => json_encode($arguments, \JSON_UNESCAPED_UNICODE)],
            ]],
        ], 'finish_reason' => 'tool_calls']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text, string $reasoning = ''): array
    {
        return ['choices' => [['message' => [
            'content' => $text,
            'reasoning_content' => '' === $reasoning ? null : $reasoning,
        ], 'finish_reason' => 'stop']]];
    }
}
