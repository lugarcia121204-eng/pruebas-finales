<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatSocketService
{
    public function changed(int $institution, int $sender, int $recipient): void
    {
        foreach ([[$sender, $recipient], [$recipient, $sender]] as [$user, $contact]) {
            $this->publish([
                'type' => 'changed', 'institution_id' => $institution, 'user_id' => $user,
                'contact_id' => $contact,
                'unread_count' => DB::table('institution_messages')->where('institution_id', $institution)
                    ->where('sender_id', $contact)->where('recipient_id', $user)->whereNull('read_at')->count(),
            ]);
        }
    }

    public function revokeSession(string $sessionId): void
    {
        $this->publish(['type' => 'revoke', 'session' => hash('sha256', $sessionId)]);
    }

    private function publish(array $payload): void
    {
        if (! config('chat.secret')) {
            return;
        }

        try {
            Http::withToken(config('chat.secret'))->connectTimeout(1)->timeout(2)
                ->post(rtrim(config('chat.socket_url'), '/').'/internal/events', $payload)->throw();
        } catch (\Throwable $exception) {
            // A delivery outage must never roll back a message already saved.
            Log::warning('Chat Socket.IO no disponible', ['exception' => $exception::class]);
        }
    }
}
