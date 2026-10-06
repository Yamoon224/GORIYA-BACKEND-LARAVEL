<?php

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Migrations\Migration;

/**
 * Fusionne les conversations en double entre les deux mêmes participants.
 * Historiquement, MessagingService::findOrCreateForCandidature() créait une
 * conversation par Candidature : un candidat postulant deux fois chez la
 * même entreprise obtenait deux fils de discussion distincts, alors qu'il
 * s'agit des deux mêmes personnes. Depuis cette migration,
 * findOrCreateForCandidature() réutilise le fil existant entre les deux
 * participants — ceci nettoie les doublons déjà créés avant ce changement.
 *
 * La conversation la plus ancienne (par created_at) est conservée ; les
 * messages des autres y sont déplacés avant de les supprimer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = Conversation::query()
            ->get()
            ->groupBy(fn (Conversation $c) => collect([$c->participant_one_id, $c->participant_two_id])->sort()->implode('|'));

        foreach ($groups as $conversations) {
            if ($conversations->count() < 2) {
                continue;
            }

            $sorted = $conversations->sortBy('created_at')->values();
            $keeper = $sorted->first();

            foreach ($sorted->slice(1) as $duplicate) {
                Message::where('conversation_id', $duplicate->id)->update(['conversation_id' => $keeper->id]);

                $keeper->starred_by = array_values(array_unique(array_merge(
                    $keeper->starred_by ?? [],
                    $duplicate->starred_by ?? [],
                )));

                $duplicate->delete();
            }

            $lastMessageAt = Message::where('conversation_id', $keeper->id)->max('created_at');
            if ($lastMessageAt) {
                $keeper->last_message_at = $lastMessageAt;
            }
            $keeper->save();
        }
    }

    /**
     * Fusion non réversible : les conversations dupliquées sont supprimées
     * (leurs messages ont rejoint celle conservée).
     */
    public function down(): void
    {
    }
};
